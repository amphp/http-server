<?php

namespace Aerys;

class Export {
    private $socket;
    private $exportId;
    private $clearExport;

    /**
     * @param resource $socket
     * @param int $exportId
     * @param callable $clearExport
     */
    public function __construct($socket, int $exportId, callable $clearExport) {
        $this->socket = $socket;
        $this->exportId = $exportId;
        $this->clearExport = $clearExport;
    }

    /**
     * Retrieve the raw exported socket
     *
     * @return resource
     */
    public function socket() {
        return $this->socket;
    }

    /**
     * Clear the HTTP server's reference to the exported socket
     *
     * When the HTTP server exports a socket it does not decrement its internal
     * client connection count. This prevents secondary application actions such
     * as websockets from overrunning the server's configurable Options::maxClients
     * directive.
     *
     * Applications must invoke Export::clear() to free up the client slot retaind
     * by the server for the exported socket. Applications may call this method at
     * any time but they MUST always call it when finished with the exported socket.
     * Failure to call the Export object's clear() method will eventually cause the
     * server to refuse all new connections; its internal client count will reach
     * the maximum it allows at any one time.
     *
     * Repeated calls to Export::clear() will have no effect.
     *
     * @return void
     */
    public function clear() {
        ($this->clearExport)($this->exportId);
    }
}
