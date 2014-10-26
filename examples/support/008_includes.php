<?php

use Amp\Success;
use Aerys\Websocket;
use Aerys\Websocket\Broadcast;

class ExampleWebsocket implements Websocket {
    private $clientCount = 0;

    public function onStart() {
        return new Success;
    }

    public function onOpen($clientId, array $httpEnvironment) {
        $this->clientCount++;
        $msg = json_encode(['type' => 'count', 'data' => $this->clientCount]);

        // Broadcast the current user count to all users. Yielding the
        // "broadcast" key with a string element is a shortcut for sending
        // the specified $msg to all clients connected to this websocket
        // endpoint. Fine-grained control over which clients receive a
        // broadcast is demonstrated in the onData() method.
        yield 'broadcast' => $msg;
    }

    public function onData($clientId, $payload) {
        $msg = json_encode(['type' => 'echo', 'data' => $payload]);

        // An empty $include array equates to "all connected clients"
        $include = [];

        // Exclude the client that sent us this message from the broadcast
        // because our javascript has already displayed the message on the
        // client end of things.
        $exclude = [$clientId];

        // Broadcast the message.
        yield new Broadcast($msg, $include, $exclude);
    }

    public function onClose($clientId, $code, $reason) {
        $this->clientCount--;
        $msg = json_encode(['type' => 'count', 'data' => $this->clientCount]);

        // Broadcast the current user count to all connected users
        yield 'broadcast' => $msg;
    }

    public function onStop() {
        return new Success;
    }
}
