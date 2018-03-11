<?php

namespace Amp\Http\Server\Websocket;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

interface Application {
    /**
     * Invoked when starting the server.
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
     * Promise the server will not start until that Promise resolves.
     *
     * @param \Amp\Http\Server\Websocket\Endpoint $endpoint
     */
    public function onStart(Endpoint $endpoint);

    /**
     * Respond to websocket handshake requests.
     *
     * If a websocket application doesn't wish to impose any special constraints on the
     * handshake it doesn't have to do anything in this method and all handshakes will
     * be automatically accepted.
     *
     * Return an instance of \Amp\Http\Server\Response to reject the websocket connection request.
     *
     * @param Request $request The HTTP request that instigated the handshake
     * @param Response $response The switching protocol response for adding headers, etc.
     *
     * @return Response|\Amp\Promise|\Generator Return the given response to accept the
     *     connection or a new responseobject to deny the connection. May also return a
     *     promise or generator to run as a coroutine.
     */
    public function onHandshake(Request $request, Response $response);

    /**
     * Invoked when the full two-way websocket upgrade completes.
     *
     * @param int $clientId A unique (to the current process) identifier for this client
     * @param Request $request The HTTP request the instigated the connection.
     */
    public function onOpen(int $clientId, Request $request);

    /**
     * Invoked when data messages arrive from the client.
     *
     * @param int     $clientId A unique (to the current process) identifier for this client
     * @param Message $message A stream of data received from the client
     */
    public function onData(int $clientId, Message $message);

    /**
     * Invoked when the close handshake completes.
     *
     * @param int $clientId A unique (to the current process) identifier for this client
     * @param int $code The websocket code describing the close
     * @param string $reason The reason for the close (may be empty)
     */
    public function onClose(int $clientId, int $code, string $reason);

    /**
     * Invoked when the server is stopping.
     *
     * If the application initialized resources in Websocket::onStart() this is the
     * place to free them.
     *
     * This method is called right before the clients will be all automatically closed.
     * There is no need to call Endpoint::close() manually in this method.
     *
     * If this method is a Generator it will be resolved as a coroutine before the server
     * is allowed to fully shutdown. Additionally, if this method returns a Promise the
     * server will not shutdown until that Promise resolves.
     */
    public function onStop();
}
