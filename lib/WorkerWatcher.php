<?php

namespace Aerys;

use Amp\Reactor;
use Amp\UvReactor;
use Amp\LibeventReactor;

class WorkerWatcher {
    private $workerCount;
    private $reactor;
    private $config;
    private $ipcUri;
    private $processes = [];
    private $ipcClients = [];
    private $workerFatalWatchers = [];
    private $isStopping = false;
    private $isReloading = false;
    private $lastWorkerId = 0;

    public function __construct(Reactor $reactor) {
        $this->reactor = $reactor;
    }

    public function watch($options) {
        $this->config = $options['config'];
        $workerCount = empty($options['workers']) ? 0 : (int) $options['workers'];
        if ($workerCount < 1) {
            $workerCount = countCpuCores();
        }
        $this->workerCount = $this->canReusePort() ? $workerCount : 1;

        $hosts = $this->validateConfig($options['config']);

        // @TODO Start remote control server (only if so_reuseport available)

        $this->startIpcServer();

        for ($i=0; $i < $this->workerCount; $i++) {
            $this->spawn();
        }

        if ($this->reactor instanceof UvReactor) {
            $this->reactor->onSignal(\UV::SIGINT, [$this, 'stop']);
            $this->reactor->onSignal(\UV::SIGTERM, [$this, 'stop']);
        } elseif ($this->reactor instanceof LibeventReactor) {
            $this->reactor->onSignal($sigint = 2, [$this, 'stop']);
            $this->reactor->onSignal($sigint = 15, [$this, 'stop']);
        } elseif (extension_loaded('pcntl')) {
            pcntl_signal(SIGINT, [$this, 'stop']);
            pcntl_signal(SIGTERM, [$this, 'stop']);
        }

        foreach ($hosts as $addr) {
            $addr = substr(str_replace('0.0.0.0', '*', $addr), 6);
            printf("Listening for HTTP traffic on %s ...\n", $addr);
        }
    }

    private function canReusePort() {
        // Windows can always bind on the same port across processes
        if (stripos(PHP_OS, 'WIN') === 0) {
            return true;
        }

        // Support for SO_REUSEPORT not present prior to PHP7
        if (PHP_MAJOR_VERSION < 7) {
            return false;
        }

        // @TODO don't be so heavy-handed :)
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        $ctx = stream_context_create(['socket' => ['so_reuseport' => true]]);
        if (!$sock1 = @stream_socket_server('127.0.0.1:0', $errno, $errstr, $flags, $ctx)) {
            return false;
        }
        $addr = stream_socket_get_name($sock1, false);
        if (!$sock2 = @stream_socket_server($addr, $errno, $errstr, $flags, $ctx)) {
            return false;
        }

        return true;
    }

    private function validateConfig($config) {
        $cmd = $this->buildConfigTestCmd($config);
        exec($cmd, $output);

        $output = implode($output, "\n");
        $data = unserialize($output);

        if (empty($data)) {
            throw new BootException('Config file validation failed :(');
        }

        // If the config-test file sent anything to STDOUT we echo it here to simplify debugging
        if ($data['output']) {
            echo $data['output'], "\n\n";
        }

        if ($data['error']) {
            throw new BootException($data['error_msg']);
        }

        $this->config = $config;

        return $data['hosts'];
    }

    private function buildConfigTestCmd($config) {
        $cmd[] = PHP_BINARY;
        if ($ini = get_cfg_var('cfg_file_path')) {
            $cmd[] = "-c $ini";
        }
        $cmd[] = __DIR__ . "/../src/worker-config-test.php";
        $cmd[] = "--config {$config}";

        return implode(' ', $cmd);
    }

    private function startIpcServer() {
        if ($ipcServer = stream_socket_server("tcp://127.0.0.1:*", $errno, $errstr)) {
            stream_set_blocking($ipcServer, false);
            $this->ipcUri = stream_socket_get_name($ipcServer, $wantPeer = false);
            $this->reactor->onReadable($ipcServer, function($reactor, $watcher, $stream) {
                $this->accept($stream);
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
            stream_set_blocking($ipcClient, false);
            $watcherId = $this->reactor->onReadable($ipcClient, function($reactor, $watcherId, $stream) {
                $this->onReadableWorker($stream);
            });
            $this->ipcClients[$clientId] = [$workerId = null, $watcherId, $ipcClient];
        }
    }

    private function onReadableWorker($ipcClient) {
        $clientId = (int) $ipcClient;
        stream_set_blocking($ipcClient, true);
        $line = @fgets($ipcClient);
        stream_set_blocking($ipcClient, false);

        if (empty($line)) {
            // Dead socket
            $this->unloadIpcClient($ipcClient);
            return;
        }

        list($type, $workerId) = array_filter(explode(" ", rtrim($line)));

        switch($type) {
            case 'init':
                $this->ipcClients[$clientId][0] = $workerId;
                break;
            case 'stop':
                $this->unloadIpcClient($ipcClient);
                break;
            case 'fatal':
                $this->onWorkerFatal($ipcClient);
                break;
            default:
                throw new \RuntimeException(
                    sprintf('Unknown IPC command received from worker: %s', $type)
                );
        }
    }

    /**
     * Set a timer to forcefully kill the worker in 2500ms if it doesn't report stop completion
     */
    private function onWorkerFatal($ipcClient) {
        $clientId = (int) $ipcClient;
        $this->workerFatalWatchers[$clientId] = $this->reactor->once(function() use ($ipcClient) {
            $this->unloadIpcClient($ipcClient, $kill = true);
        }, $msDelay = 2500);

        $this->spawn();
    }

    private function unloadIpcClient($ipcClient, $kill = false) {
        $clientId = (int) $ipcClient;

        list($workerId, $watcherId) = $this->ipcClients[$clientId];
        unset($this->ipcClients[$clientId]);
        $this->reactor->cancel($watcherId);
        @fclose($ipcClient);

        $hasFatalWatcher = isset($this->workerFatalWatchers[$clientId]);

        if ($hasFatalWatcher) {
            $this->reactor->cancel($this->workerFatalWatchers[$clientId]);
            unset($this->workerFatalWatchers[$clientId]);
        }

        $procHandle = $this->processes[$workerId];

        if ($kill) {
            @proc_terminate($procHandle);
        }

        @proc_close($procHandle);

        unset($this->processes[$workerId]);

        if ($this->isStopping && empty($this->ipcClients)) {
            $this->isStopping = false;
            $this->reactor->stop();
            return;
        } elseif (!($this->isStopping || $this->isReloading || $hasFatalWatcher)) {
            for ($i=count($this->ipcClients); $i<$this->workerCount; $i++) {
                $this->spawn();
            }
        } elseif ($this->isReloading && count($this->ipcClients) === $this->workerCount) {
            $this->isReloading = false;
        }
    }

    public function stop() {
        if (!$this->isStopping) {
            $this->isStopping = true;
            $this->sendWorkerStopCommands();
        }
    }

    private function sendWorkerStopCommands() {
        foreach ($this->ipcClients as $clientId => $clientStruct) {
            $ipcClient = $clientStruct[2];
            @stream_set_blocking($ipcClient, true);
            if (!@fwrite($ipcClient, ".")) {
                $this->unloadIpcClient($ipcClient);
            } else {
                @stream_set_blocking($ipcClient, false);
            }
        }
    }

    public function reload() {
        if (!($this->isReloading || $this->isStopping)) {
            $this->isReloading = true;
            for ($i=0; $i < $this->workerCount; $i++) {
                $this->spawn();
            }
            $this->outputListenAddresses();
        }
    }

    private function spawn() {
        $workerId = ++$this->lastWorkerId;
        $parts[] = PHP_BINARY;
        if ($ini = get_cfg_var('cfg_file_path')) {
            $parts[] = "-c \"{$ini}\"";
        }
        $parts[] = __DIR__ . "/../src/worker.php";
        $parts[] = "--config {$this->config}";
        $parts[] = "--ipcuri {$this->ipcUri}";
        $parts[] = "--id {$workerId}";

        $cmd = implode(" ", $parts);

        if (!$procHandle = @proc_open($cmd, [], $pipes)) {
            throw new \RuntimeException(
                'Failed opening worker process pipe'
            );
        }

        $this->processes[$workerId] = $procHandle;
    }
}
