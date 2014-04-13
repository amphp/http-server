<?php

namespace Aerys\Watch;

use Alert\Reactor, Aerys\Server;

class ProcessWorker {
    private $reactor;
    private $server;
    private $watcher;
    private $isStopping = FALSE;

    public function __construct(Reactor $reactor, Server $server) {
        $this->reactor = $reactor;
        $this->server = $server;
    }

    public function start($ipcUri) {
        $socket = @stream_socket_client($ipcUri, $errno, $errstr);

        if ($socket) {
            stream_set_blocking($socket, FALSE);
            $this->watcher = $this->reactor->onReadable($socket, [$this, 'stop']);
        } else {
            throw new \RuntimeException(
                sprintf('Failed connecting to IPC server %s: [%d] %s', $ipcUri, $errno, $errstr)
            );
        }
    }

    public function stop() {
        if (!$this->isStopping) {
            $this->isStopping = TRUE;
            $this->reactor->cancel($this->watcher);
            $future = $this->server->stop();
            $future->onComplete([$this->reactor, 'stop']);
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
