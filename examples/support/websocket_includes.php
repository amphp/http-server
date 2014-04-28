<?php

use Aerys\Websocket\App, Aerys\Websocket\Broker;

class ExampleWebsocketApp implements App {
    private $broker;
    private $sockets = [];

    public function start(Broker $broker) {
        $this->broker = $broker;
    }

    public function onOpen($socketId, array $httpEnvironment) {
        $this->sockets[$socketId] = $socketId;

        // Broadcast the new user count to all users
        $this->broadcastUserCount();
    }

    private function broadcastUserCount() {
        $msgType = 'count';
        $msgData = count($this->sockets);
        $msg = json_encode(['type' => $msgType, 'data' => $msgData]);

        $recipients = array_values($this->sockets);

        $this->broker->broadcast($msg, $recipients);
    }

    public function onData($socketId, $payload, array $context) {
        $msgType = 'echo';
        $msgData = $payload;
        $msg = json_encode(['type' => $msgType, 'data' => $msgData]);

        // Send our message to all connected clients except
        // the $socketId that originated it
        $recipients = $this->sockets;
        unset($recipients[$socketId]);

        $this->broker->broadcast($msg, $recipients);
    }

    public function onClose($socketId, $code, $reason) {
        // The socket is closed, lets clear it from our records
        unset($this->sockets[$socketId]);

        // Broadcast the updated user count to all remaining users
        $this->broadcastUserCount();
    }

    public function stop() {
        // If you need to cleanup resources from start() do it here
    }
}
