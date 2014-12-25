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
    private $hostAddrs;
    private $processes = [];
    private $ipcClients = [];
    private $workerFatalWatchers = [];
    private $isStopping = false;
    private $isReloading = false;
    private $lastWorkerId = 0;

    /**
     * We can't bind the same IP:PORT in multiple separate processes if we aren't
     * in a Windows environment. Non-windows operating systems should have ext/pcntl
     * or pecl/pthreads, so this limitation is really only enforced in development
     * environments.
     *
     * @TODO Allow multiple workers in non-windows environments if SO_REUSEPORT is available
     */
    public function __construct($workerCount, Reactor $reactor = null) {
        $workerCount = (int) $workerCount;

        if (stripos(PHP_OS, 'WIN') !== 0) {
            $this->workerCount = 1;
        } elseif ($workerCount > 0) {
            $this->workerCount = $workerCount;
        } else {
            $this->workerCount = countCpuCores();
        }

        $this->reactor = $reactor ?: \Amp\getReactor();
    }

    public function watch($configFile) {
        $this->validateConfig($configFile);
        $this->startIpcServer();

        for ($i=0; $i < $this->workerCount; $i++) {
            $this->spawn();
        }

        foreach ($this->hostAddrs as $address) {
            $address = substr(str_replace('0.0.0.0', '*', $address), 6);
            printf("Listening for HTTP traffic on %s ...\n", $address);
        }

        if ($this->reactor instanceof UvReactor) {
            $this->reactor->onSignal(\UV::SIGINT, [$this, 'stop']);
        } elseif ($this->reactor instanceof LibeventReactor) {
            $this->reactor->onSignal($sigint = 2, [$this, 'stop']);
        } elseif (extension_loaded('pcntl')) {
            pcntl_signal(SIGINT, [$this, 'stop']);
        }
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
        $this->hostAddrs = $data['hosts'];
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
     * Set a timer to kill the worker in 1000ms if it doesn't report stop completion
     */
    private function onWorkerFatal($ipcClient) {
        $clientId = (int) $ipcClient;
        $this->workerFatalWatchers[$clientId] = $this->reactor->once(function() use ($ipcClient) {
            $this->unloadIpcClient($ipcClient);
        }, $msDelay = 1000);

        $this->spawn();
    }

    private function unloadIpcClient($ipcClient) {
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
        @proc_terminate($procHandle);
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
        $parts = [];
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
