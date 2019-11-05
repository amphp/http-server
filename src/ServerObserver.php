<?php

namespace Amp\Http\Server;

use Amp\Promise;

/**
 * Allows reacting to server start and stop events.
 */
interface ServerObserver
{
    /**
     * Invoked when the server is starting. Server sockets have been opened, but are not yet accepting client
     * connections. This method should be used to set up any necessary state for responding to requests, including
     * starting loop watchers such as timers.
     *
     * @param HttpServer $server
     *
     * @return Promise
     */
    public function onStart(HttpServer $server): Promise;

    /**
     * Invoked when the server has initiated stopping. No further requests are accepted and any connected clients
     * should be closed gracefully and any loop watchers cancelled.
     *
     * @param HttpServer $server
     *
     * @return Promise
     */
    public function onStop(HttpServer $server): Promise;
}
