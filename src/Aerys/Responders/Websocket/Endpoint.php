<?php

namespace Aerys\Responders\Websocket;

interface Endpoint {

    /**
     * Invoked when the websocket broker is ready to accept clients on the endpoint
     *
     * Websocket endpoints do not communicate directly with the connected sockets. Instead, endpoints
     * tell a broker instance what and to whom data should be sent and the broker takes care of the
     * protocol details so applications don't have to know anything about RFC 6455. Endpoints must
     * store a reference to the broker object when Endpoint::onStart is invoked or they won't be
     * able to communicate with clients who connect to the endpoint.
     *
     * Websocket endpoints have access to a wide range of functionality and information retrieval
     * for connected client sockets through their broker objects. This functionality may be explored
     * inside the Broker class API.
     *
     * @param \Aerys\Responders\Websocket\Broker $broker
     */
    function onStart(Broker $broker);

    /**
     * Invoked when a new client connects to the endpoint
     *
     * @param int $socketId A unique identifier mapping to the newly connected client socket
     */
    function onOpen($socketId);

    /**
     * Invoked when a data message is received from a connected endpoint client
     *
     * @param int $socketId A unique identifier mapping to the newly connected client socket
     * @param \Aerys\Responders\Websocket\Message $message The received websocket data
     */
    function onMessage($socketId, Message $message);

    /**
     * Invoked after a client disconnection
     *
     * @param int $socketId A unique identifier mapping to the newly connected client socket
     * @param int $code A numeric code indicating the "why" of the disconnection
     * @param string $reason A brief description of the close event
     */
    function onClose($socketId, $code, $reason);

}
