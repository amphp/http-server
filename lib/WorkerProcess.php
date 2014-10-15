<?php

namespace Aerys;

use Amp\Reactor;
use Amp\SignalReactor;

class WorkerProcess {
    const SIGINT = 2;
    const SIGTERM = 15;

    private $reactor;
    private $server;
    private $watcher;
    private $isStopping = FALSE;

    public function __construct(Reactor $reactor, Server $server) {
        $this->reactor = $reactor;
        $this->server = $server;
    }

    public function register() {
        register_shutdown_function([$this, 'shutdown']);

        if ($this->reactor instanceof SignalReactor) {
            $this->reactor->onSignal(self::SIGINT, [$this, 'stop']);
            $this->reactor->onSignal(self::SIGTERM, [$this, 'stop']);
        } elseif (extension_loaded('pcntl')) {
            pcntl_signal(SIGINT, [$this, 'stop']);
            pcntl_signal(SIGTERM, [$this, 'stop']);
        }

        return $this;
    }

    public function start($ipcUri) {
        if ($socket = @stream_socket_client($ipcUri, $errno, $errstr)) {
            stream_set_blocking($socket, FALSE);
            $this->watcher = $this->reactor->onReadable($socket, [$this, 'stop']);
        } else {
            throw new \RuntimeException(
                sprintf('Failed connecting to IPC server %s: [%d] %s', $ipcUri, $errno, $errstr)
            );
        }

        return $this;
    }

    public function run() {
        $this->reactor->run();
    }

    public function stop() {
        if (!$this->isStopping) {
            $this->isStopping = TRUE;
            $this->reactor->cancel($this->watcher);
            $this->server->stop()->when([$this->reactor, 'stop']);
        }
    }

    public function shutdown() {
        $fatals = [E_ERROR, E_PARSE, E_USER_ERROR, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING];
        $lastError = error_get_last();
        if ($lastError && in_array($lastError['type'], $fatals)) {
            $this->stop();
        }
    }
}
