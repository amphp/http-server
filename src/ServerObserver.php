<?php

namespace Amp\Http\Server;

/**
 * Allows reacting to server start and stop events.
 */
interface ServerObserver
{
    /**
     * Invoked when the server is starting.
     *
     * Server sockets have been opened, but are not yet accepting client connections. This method should be used to set
     * up any necessary state for responding to requests, including starting event loop callbacks such as timers.
     */
    public function onStart(HttpServer $server): void;

    /**
     * Invoked when the server has initiated stopping.
     *
     * No further requests are accepted and any connected clients should be closed gracefully and any event loop
     * callbacks cancelled.
     */
    public function onStop(HttpServer $server): void;
}
