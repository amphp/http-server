<?php

namespace Aerys;

interface Websocket {
    /**
     * Invoked when starting the server
     *
     * All messages are sent to connected clients by calling methods on the
     * Endpoint instance passed in onStart(). Applications must store
     * the endpoint instance for use once the server starts.
     *
     * If the websocket application has external resources it needs to initialize
     * (like database connections) this is the place to do it.
     *
     * If this method is a Generator it will be resolved as a coroutine before
     * the server is allowed to start. Additionally, this method returns a
     * Promise the server will not start until that promise resolves.
     *
     * @param \Aerys\Websocket\Endpoint $endpoint
     */
    public function onStart(Websocket\Endpoint $endpoint);

    /**
     * Respond to websocket handshake requests
     *
     * If a websocket application doesn't wish to impose any special constraints on the
     * handshake it doesn't have to do anything in this method and all handshakes will
     * be automatically accepted.
     *
     * The return value from onHandshake() invocation (which may be the eventual generator
     * return expression) is passed as the second parameter to onOpen().
     *
     * @param \Aerys\Request $request The HTTP request that instigated the handshake
     * @param \Aerys\Response $response Used to set headers and/or reject the handshake
     */
    public function onHandshake(Request $request, Response $response);

    /**
     * Invoked when the full two-way websocket upgrade completes
     *
     * @param int $clientId A unique (to the current process) identifier for this client
     * @param mixed $handshakeData The return value from onHandshake() for this client
     */
    public function onOpen(int $clientId, $handshakeData);

    /**
     * Invoked when data messages arrive from the client
     *
     * @param int $clientId A unique (to the current process) identifier for this client
     * @param \Aerys\Websocket\Message $msg A stream of data received from the client
     */
    public function onData(int $clientId, Websocket\Message $msg);

    /**
     * Invoked when the close handshake completes
     *
     * @param int $clientId A unique (to the current process) identifier for this client
     * @param int $code The websocket code describing the close
     * @param string $reason The reason for the close (may be empty)
     */
    public function onClose(int $clientId, int $code, string $reason);

    /**
     * Invoked when the server is stopping
     *
     * If the application initialized resources in Websocket::onStart() this is the
     * place to free them.
     *
     * This method is called right before the clients will be all automatically closed.
     * There is no need to call Endpoint::close() manually in this method.
     *
     * If this method is a Generator it will be resolved as a coroutine before the server
     * is allowed to fully shutdown. Additionally, if this method returns a Promise the
     * server will not shutdown until that promise resolves.
     */
    public function onStop();
}
