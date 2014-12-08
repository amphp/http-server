<?php

namespace Aerys\Websocket;

use Aerys\Responder;
use Aerys\ResponderEnvironment;

class HandshakeResponder implements Responder {
    private $endpoint;
    private $preparedEnvironment;

    /**
     * @param Endpoint $endpoint
     */
    public function __construct(Endpoint $endpoint) {
        $this->endpoint = $endpoint;
    }

    /**
     * Prepare the Responder for client output
     *
     * @param \Aerys\ResponderEnvironment $env
     */
    public function prepare(ResponderEnvironment $env) {
        $this->preparedEnvironment = $env;
    }

    /**
     * Assume control of the client socket and output the prepared response
     *
     * Instead of writing the handshake response at this time we export the socket
     * from the server. This allows us to JIT the handshake response based on what
     * the application's Websocket::onOpen() method chooses to do with the client
     * socket given the HTTP handshake request.
     *
     * Exporting the socket at this time is safe because we're either going to proceed
     * with the protocol upgrade or we're going to fail the handshake and close the
     * connection. Neither execution path requires further interaction with the HTTP
     * server.
     *
     * @return void
     */
    public function assumeSocketControl() {
        $env = $this->preparedEnvironment;
        $request = $env->request;
        $socket = $env->socket;
        $server = $env->server;
        $onCloseCallback = $server->exportSocket($socket);
        $this->endpoint->importSocket($socket, $onCloseCallback, $request);
    }

    /**
     * The Responder API contract requires this method
     *
     * Websocket endpoints register their own IO writability watchers so we don't have any use
     * for the write watcher registered by the HTTP server at client connect time. As a result
     * we can safely leave this empty as it's never used as part of the websocket handshake.
     *
     * @return void
     */
    public function write() {}
}
