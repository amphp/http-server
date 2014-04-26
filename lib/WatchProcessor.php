<?php

namespace Aerys;

use Alert\Reactor, Alert\ReactorFactory;

class WatchProcessor extends Watcher {
    private $reactor;
    private $debug;
    private $config;
    private $ipcUri;
    private $workerCount;
    private $hostAddrs;
    private $processes = [];
    private $ipcClients = [];
    private $isStopping = FALSE;
    private $isReloading = FALSE;

    public function __construct(Reactor $reactor = NULL) {
        $this->reactor = $reactor ?: (new ReactorFactory)->select();
    }

    public function watch(BinOptions $binOptions) {
        $this->debug = $binOptions->getDebug();
        $this->validateConfig($binOptions->getConfig(), $isReload = FALSE);
        $this->setWorkerCount($binOptions->getWorkers());
        $this->startIpcServer();

        for ($i=0; $i < $this->workerCount; $i++) {
            $this->spawn();
        }

        foreach ($this->hostAddrs as $address) {
            $address = substr(str_replace('0.0.0.0', '*', $address), 6);
            printf("Listening for HTTP traffic on %s ...\n", $address);
        }

        $this->reactor->run();
    }

    private function validateConfig($config, $isReload) {
        $cmd = $this->buildConfigTestCmd($config, $isReload);
        exec($cmd, $output);

        $output = implode($output, "\n");
        $data = @unserialize($output);

        if (empty($data)) {
            throw new BootException($output);
        } elseif ($data['error']) {
            throw new BootException($data['error_msg']);
        }

        $this->config = $config;
        $this->hostAddrs = $data['hosts'];
    }

    private function buildConfigTestCmd($config, $isReload) {
        $cmd[] = PHP_BINARY;
        if ($ini = get_cfg_var('cfg_file_path')) {
            $cmd[] = "-c $ini";
        }
        $cmd[] = __DIR__ . "/../src/config-test.php";
        $cmd[] = "--config {$config}";
        if ($this->debug) {
            $cmd[] = "--debug";
        }
        if (!$isReload) {
            $cmd[] = "--bind";
        }

        return implode(' ', $cmd);
    }

    /**
     * We can't bind the same IP:PORT in multiple separate processes if we aren't
     * in a Windows environment. Non-windows operating systems should have ext/pcntl
     * availability, so this doesn't represent a real performance impediment.
     */
    private function setWorkerCount($requestedWorkerCount) {
        if (stripos(PHP_OS, 'WIN') !== 0) {
            $this->workerCount = 1;
        } elseif ($requestedWorkerCount > 0) {
            $this->workerCount = $requestedWorkerCount;
        } else {
            $this->workerCount = $this->countCpuCores();
        }
    }

    private function startIpcServer() {
        if ($ipcServer = stream_socket_server("tcp://127.0.0.1:*", $errno, $errstr)) {
            stream_set_blocking($ipcServer, FALSE);
            $this->ipcUri = stream_socket_get_name($ipcServer, $wantPeer = FALSE);
            $this->reactor->onReadable($ipcServer, function() use ($ipcServer) {
                $this->accept($ipcServer);
            });
        } else {
            throw new \RuntimeException(
                sprintf("Failed binding socket server on %s: [%d] %s", $uri, $errno, $errstr)
            );
        }
    }

    private function accept($ipcServer) {
        while ($ipcClient = @stream_socket_accept($ipcServer, $timeout = 0)) {
            $clientId = (int) $ipcClient;
            stream_set_blocking($ipcClient, FALSE);
            $watcherId = $this->reactor->onReadable($ipcClient, function($watcherId, $ipcClient) {
                $clientId = (int) $ipcClient;
                $this->unloadIpcClient($clientId, $watcherId, $ipcClient);
            });
            $this->ipcClients[$clientId] = [$watcherId, $ipcClient];
        }
    }

    private function unloadIpcClient($clientId, $watcherId, $ipcClient) {
        unset($this->ipcClients[$clientId]);
        $this->reactor->cancel($watcherId);
        if (is_resource($ipcClient)) {
            @fclose($ipcClient);
        }

        $this->collect();

        if ($this->isStopping && empty($this->ipcClients)) {
            $this->isStopping = FALSE;
            $this->reactor->stop();
            return;
        } elseif (!($this->isStopping || $this->isReloading)) {
            for ($i=count($this->ipcClients); $i<$this->workerCount; $i++) {
                $this->spawn();
            }
        } elseif ($this->isReloading && count($this->ipcClients) === $this->workerCount) {
            $this->isReloading = FALSE;
        }
    }

    private function collect() {
        foreach ($this->processes as $key => $procStruct) {
            list($procHandle, $stdinPipe) = $procStruct;
            $info = @proc_get_status($procHandle);
            if (!($info && $info['running'])) {
                @fclose($stdinPipe);
                @proc_terminate($procHandle);
                unset($this->processes[$key]);
            }
        }
    }

    public function stop() {
        if (!$this->isStopping) {
            $this->isStopping = TRUE;
            $this->notifyWorkers();
        }
    }

    private function notifyWorkers() {
        foreach ($this->ipcClients as $clientId => $clientStruct) {
            list($watcherId, $ipcClient) = $clientStruct;
            @stream_set_blocking($ipcClient, TRUE);
            if (!@fwrite($ipcClient, ".")) {
                $this->unloadIpcClient($clientId, $watcherId, $ipcClient);
            } else {
                stream_set_blocking($ipcClient, FALSE);
            }
        }
    }

    public function reload() {
        if (!($this->isReloading || $this->isStopping)) {
            $this->isReloading = TRUE;
            for ($i=0; $i < $this->workerCount; $i++) {
                $this->spawn();
            }
            $this->outputListenAddresses();
        }
    }

    private function spawn() {
        $parts[] = PHP_BINARY;
        if ($ini = get_cfg_var('cfg_file_path')) {
            $parts[] = "-c \"{$ini}\"";
        }
        $parts[] = __DIR__ . "/../src/worker.php";
        $parts[] = "--config {$this->config}";
        $parts[] = "--ipcuri {$this->ipcUri}";
        if ($this->debug) {
            $parts[] = "--debug";
        }

        $cmd = implode(" ", $parts);

        if (!$procHandle = @proc_open($cmd, [["pipe", "r"]], $pipes)) {
            throw new \RuntimeException(
                'Failed opening worker process pipe'
            );
        }

        $this->processes[] = [$procHandle, $pipes[0]];
    }
}
