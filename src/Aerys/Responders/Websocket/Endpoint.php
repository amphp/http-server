<?php

namespace Aerys\Responders\Websocket;

interface Endpoint {

    /**
     * Invoked when a new client connects to the endpoint
     *
     * If Endpoint::onOpen yields/returns a string or seekable stream that value is sent to the
     * $socketId that connected to initiate the onOpen event. This action is equivalent to calling
     * $broker->sendText($socketId, $data). If you need to return BINARY data you must manually call
     * $broker->sendBinary($socketId, $data).
     *
     * @param \Aerys\Responders\Websocket\Broker $broker
     * @param int $socketId A unique identifier mapping to the newly connected client socket
     */
    function onOpen(Broker $broker, $socketId);

    /**
     * Invoked when a data message is received from a connected endpoint client
     *
     * If Endpoint::onMessage yields/returns a string or seekable stream that value is sent to the
     * $socketId reponsible for the message triggering the onMessage event. This action is the same
     * as calling $broker->sendText($socketId, $data). If you need to return BINARY data you must
     * manually call $broker->sendBinary($socketId, $data).
     *
     * @param \Aerys\Responders\Websocket\Broker $broker
     * @param int $socketId A unique identifier mapping to the newly connected client socket
     * @param \Aerys\Responders\Websocket\Message $message The received websocket data
     */
    function onMessage(Broker $broker, $socketId, Message $message);

    /**
     * Invoked AFTER a client disconnects.
     *
     * Endpoint::onClose implementations may use yield to act as generators and cooperatively
     * multitask with the websocket responder. However, unlike Endpoint::onOpen and
     * Endpoint::onMessage any uncallable values yielded or returned by this method will be
     * discarded.
     *
     * @param \Aerys\Responders\Websocket\Broker $broker
     * @param int $socketId A unique identifier mapping to the newly connected client socket
     * @param int $code A numeric code indicating the "why" of the disconnection
     * @param string $reason A brief description of the close event
     */
    function onClose(Broker $broker, $socketId, $code, $reason);

}
