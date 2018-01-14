<?php

namespace Aerys;

use Amp\Promise;
use Psr\Log\LoggerInterface as PsrLogger;

interface ServerObserver {
    /**
     * Invoked when the server is starting. Server sockets have been opened, but are not yet accepting client
     * connections. This method should be used to set up any necessary state for responding to requests, including
     * starting loop watchers such as timers.
     *
     * @param \Aerys\Server $server
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Aerys\ErrorHandler $errorHandler
     *
     * @return \Amp\Promise
     */
    public function onStart(Server $server, PsrLogger $logger, ErrorHandler $errorHandler): Promise;

    /**
     * Invoked when the server has initiated stopping. No further requests are accepted and any connected clients
     * should be closed gracefully and any loop watchers cancelled.
     *
     * @param \Aerys\Server $server
     *
     * @return \Amp\Promise
     */
    public function onStop(Server $server): Promise;
}
