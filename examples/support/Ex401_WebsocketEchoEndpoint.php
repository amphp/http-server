<?php

use Aerys\Responders\Websocket\Message,
    Aerys\Responders\Websocket\Endpoint,
    Aerys\Responders\Websocket\Broker;

class Ex401_WebsocketEchoEndpoint implements Endpoint {

    const RECENT_MSG_LIMIT = 10;
    const RECENT_MSG_PREFIX = '0';
    const USER_COUNT_PREFIX = '1';
    const USER_ECHO_PREFIX = '2';

    private $sockets = [];
    private $recentMessages = [];

    function onOpen(Broker $broker, $socketId) {
        $this->sockets[$socketId] = $socketId;
        $this->broadcastUserCount($broker);
        $openMessage = self::RECENT_MSG_PREFIX . json_encode($this->recentMessages);

        return $openMessage;
    }

    private function broadcastUserCount(Broker $broker) {
        $recipients = array_values($this->sockets);
        $msg = self::USER_COUNT_PREFIX . count($this->sockets);
        $broker->sendText($recipients, $msg);
    }

    function onMessage(Broker $broker, $socketId, Message $msg) {
        $payload = $msg->getPayload();
        $msgToSendClients = self::USER_ECHO_PREFIX . $payload;

        // Only keep the last N messages in memory
        if (array_unshift($this->recentMessages, $payload) > self::RECENT_MSG_LIMIT) {
            array_pop($this->recentMessages);
        }

        // Send our message to all connected clients except the $socketId that originated it
        $recipients = $this->sockets;
        unset($recipients[$socketId]);

        $broker->sendText($recipients, $msgToSendClients);
    }

    function onClose(Broker $broker, $socketId, $code, $reason) {
        // The socket is closed, lets clear it from our records
        unset($this->sockets[$socketId]);

        // Broadcast the updated user count to all remaining users
        $this->broadcastUserCount($broker);
    }

}
