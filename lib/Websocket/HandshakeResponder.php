<?php

namespace Aerys\Websocket;

use Aerys\Responder;
use Aerys\ResponderStruct;

class HandshakeResponder implements Responder {
    private $endpoint;
    private $responderStruct;

    /**
     * @param Endpoint $endpoint
     */
    public function __construct(Endpoint $endpoint) {
        $this->endpoint = $endpoint;
    }

    /**
     * Prepare the Responder
     *
     * @param \Aerys\ResponderStruct $responderStruct
     */
    public function prepare(ResponderStruct $responderStruct) {
        $this->responderStruct = $responderStruct;
    }

    /**
     * Invoked by the server when it's time to write the response to the client
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
    public function write() {
        $server = $this->responderStruct->server;
        $request = $this->responderStruct->request;
        $socketId = $request['AERYS_SOCKET_ID'];
        list($socket, $onClose) = $server->exportSocket($socketId);
        $this->endpoint->import($socket, $onClose, $request);
    }
}