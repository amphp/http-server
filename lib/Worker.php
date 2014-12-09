<?php

namespace Aerys;

use Amp\Reactor;
use Amp\UvReactor;
use Amp\LibeventReactor;

class Worker {
    private $workerId;
    private $server;
    private $reactor;
    private $ipcSocket;
    private $ipcWatcher;
    private $isStopping;

    public function __construct($workerId, $ipcUri, Server $server, Reactor $reactor = null) {
        $this->workerId = $workerId;
        $this->ipcUri = $ipcUri;
        $this->server = $server;
        $this->reactor = $reactor ?: \Amp\getReactor();
    }

    public function start() {
        register_shutdown_function([$this, 'shutdown']);

        if (!$this->ipcSocket = @stream_socket_client($this->ipcUri, $errno, $errstr)) {
            throw new \RuntimeException(
                sprintf('Failed connecting to IPC server %s: [%d] %s', $this->ipcUri, $errno, $errstr)
            );
        }

        if (!@fwrite($this->ipcSocket, "init {$this->workerId}\n")) {
            throw new \RuntimeException(
                sprintf('Failed initializing IPC session: [%d] %s', $errno, $errstr)
            );
        }

        if ($this->reactor instanceof UvReactor) {
            $this->reactor->onSignal(\UV::SIGINT, [$this, 'stop']);
        } elseif ($this->reactor instanceof LibeventReactor) {
            $this->reactor->onSignal($sigint = 2, [$this, 'stop']);
        } elseif (extension_loaded('pcntl')) {
            pcntl_signal(SIGINT, [$this, 'stop']);
        }

        stream_set_blocking($this->ipcSocket, false);
        $this->ipcWatcher = $this->reactor->onReadable($this->ipcSocket, [$this, 'stop']);
    }

    public function stop() {
        if (!$this->isStopping) {
            $this->isStopping = true;
            $this->reactor->cancel($this->ipcWatcher);
            $this->server->stop()->when([$this, 'onServerStopCompletion']);
        }
    }

    public function onServerStopCompletion() {
        stream_set_blocking($this->ipcSocket, true);
        fwrite($this->ipcSocket, "stop {$this->workerId}\n");
        exit(0);
    }

    public function shutdown() {
        if (!$err = error_get_last()) {
            return;
        }

        switch ($err['type']) {
            case E_ERROR:
            case E_PARSE:
            case E_USER_ERROR:
            case E_CORE_ERROR:
            case E_CORE_WARNING:
            case E_COMPILE_ERROR:
            case E_COMPILE_WARNING:
                stream_set_blocking($this->ipcSocket, true);
                fwrite($this->ipcSocket, "fatal {$this->workerId}\n");
                stream_set_blocking($this->ipcSocket, false);
                $this->stop();
                break;
        }
    }
}
