<?php

use Aerys\Responders\Websocket\Message,
    Aerys\Responders\Websocket\Endpoint,
    Aerys\Responders\Websocket\Broker;

function asyncMultiply($x, $y, callable $onCompletion) {
    $result = $x*$y; // <-- in reality we'd use a non-blocking lib to do something here
    $onCompletion($result); // <-- array($result) is returned to our generator
}

class Ex401_WebsocketEchoEndpoint implements Endpoint {

    const RECENT_MSG_LIMIT = 10;
    const RECENT_MSG_PREFIX = '0';
    const USER_COUNT_PREFIX = '1';
    const USER_ECHO_PREFIX = '2';

    private $broker;
    private $sockets = [];
    private $recentMessages = [];

    function onStart(Broker $broker) {
        $this->broker = $broker;
    }

    function onOpen($socketId) {
        $this->sockets[$socketId] = $socketId;
        $this->broadcastUserCount();
        $openMessage = self::RECENT_MSG_PREFIX . json_encode($this->recentMessages);

        return $openMessage;
    }

    private function broadcastUserCount() {
        $recipients = array_values($this->sockets);
        $msg = self::USER_COUNT_PREFIX . count($this->sockets);
        $this->broker->sendText($recipients, $msg);
    }

    function onMessage($socketId, Message $msg) {
        $payload = $msg->getPayload();

        // Only keep the last N messages in memory
        if (array_unshift($this->recentMessages, $payload) > self::RECENT_MSG_LIMIT) {
            array_pop($this->recentMessages);
        }

        $recipients = $this->sockets;

        // Don't send this message to the client that originated it!
        unset($recipients[$socketId]);

        $msg = self::USER_ECHO_PREFIX . $payload;

        $this->broker->sendText($recipients, $msg);
    }

    function onClose($socketId, $code, $reason) {
        // The socket is closed, lets clear it from our records
        unset($this->sockets[$socketId]);

        // Broadcast the updated user count to all remaining users
        $this->broadcastUserCount();
    }

}
