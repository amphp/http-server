<?php

namespace Aerys\Watch;

use Alert\Reactor,
    Alert\ReactorFactory,
    Aerys\Start\BinOptions;

class ProcessWatcher implements ServerWatcher {
    use CpuCounter;

    private $reactor;
    private $binOptions;
    private $ipcPort;

    public function __construct(Reactor $reactor = NULL) {
        $this->reactor = $reactor ?: (new ReactorFactory)->select();
    }

    public function watch(BinOptions $binOptions) {
        $this->binOptions = $binOptions;
        $this->validateConfig();

        $workerCount = $this->determineWorkerCount();
        $this->startIpcServer();

        for ($i=0; $i < $workerCount; $i++) {
            $this->spawn();
        }

        $this->reactor->run();
    }

    private function validateConfig() {
        $appConfigPath = escapeshellarg($this->binOptions->getConfig());
        $cmd = $this->makeValidationCmd($appConfigPath);
        exec($cmd, $output, $exitCode);

        $output = implode($output, "\n");
        $data = @unserialize($output);

        if (empty($data)) {
            throw new StartException($output);
        } elseif ($data['error']) {
            throw new StartException($data['error_msg']);
        } else {
            $addresses = array_unique($data['hosts']);
            foreach ($addresses as $address) {
                $address = substr(str_replace('0.0.0.0', '*', $address), 6);
                printf("Listening for HTTP traffic on %s ...\n", $address);
            }
        }
    }

    private function makeValidationCmd($appConfigPath) {
        $cmd[] = PHP_BINARY;
        if ($ini = get_cfg_var('cfg_file_path')) {
            $cmd[] = "-c $ini";
        }
        $cmd[] = __DIR__ . "/../../src/config-test.php -c";
        $cmd[] = $appConfigPath;
        $cmd[] = ' -b';

        return implode(' ', $cmd);
    }

    /**
     * We can't bind the same IP:PORT in multiple separate processes if we aren't in a
     * Windows environment. Non-windows OS should have ext/pcntl availability, so this
     * doesn't represent a real impediment.
     */
    private function determineWorkerCount() {
        if (stripos(PHP_OS, 'WIN') !== 0) {
            return 1;
        } else {
            return $this->binOptions->getWorkers() ?: $this->countCpuCores();
        }
    }

    private function startIpcServer() {
        $ipcPort = $this->binOptions->getBackend() ?: '*';
        $ipcServer = stream_socket_server("tcp://127.0.0.1:{$ipcPort}", $errno, $errstr);

        if (!$ipcServer) {
            throw new \RuntimeException(
                sprintf("Failed binding socket server on %s: [%d] %s", $uri, $errno, $errstr)
            );
        }

        stream_set_blocking($ipcServer, FALSE);

        $this->reactor->onReadable($ipcServer, function() use ($ipcServer) {
            $this->accept($ipcServer);
        });

        $ipcName = stream_socket_get_name($ipcServer, $wantPeer = FALSE);
        $ipcPort = substr($ipcName, strrpos($ipcName, ':') + 1);

        $opts = $this->binOptions->toArray();
        $opts['backend'] = $ipcPort;

        $this->binOptions->loadOptions($opts);
    }

    private function accept($ipcServer) {
        while ($ipcClient = @stream_socket_accept($ipcServer, $timeout = 0)) {
            stream_set_blocking($ipcClient, FALSE);
            $this->reactor->onReadable($ipcClient, function($watcherId, $ipcClient) {
                $this->onReadableClient($watcherId, $ipcClient);
            });
        }
    }

    private function onReadableClient($watcherId, $ipcClient) {
        $this->reactor->cancel($watcherId);
        @fclose($ipcClient);
        $this->spawn();
    }

    public function spawn() {
        $exe = __DIR__ . "/../../src/worker.php";
        $ini = ($ini = get_cfg_var('cfg_file_path')) ? " -c \"{$ini}\"" : '';
        $cmd = sprintf("%s%s %s %s", PHP_BINARY, $ini, $exe, $this->binOptions);
        $process = popen($cmd, "r");

        if (is_resource($process)) {
            stream_set_blocking($process, FALSE);
            $this->reactor->onReadable($process, function($watcherId, $process) {
                $this->onReadableProcess($watcherId, $process);
            });
        } else {
            throw new \RuntimeException(
                'Failed opening worker process pipe'
            );
        }
    }

    private function onReadableProcess($watcherId, $process) {
        $data = fread($process, 8192);

        if ($data || $data === '0') {
            echo $data;
        } elseif (!is_resource($process) || @feof($process)) {
            $this->spawn();
            $this->reactor->cancel($watcherId);
            @pclose($process);
        }
    }
}
