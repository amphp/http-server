<?php

class ExampleWebsocket implements Aerys\Websocket {
    private $clientCount = 0;

    public function onOpen($clientId, array $httpEnvironment) {
        $msg = json_encode(['type' => 'count', 'data' => ++$this->clientCount]);

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
        // because our javascript has already displayed it at the front end
        $exclude = [$clientId];

        // Broadcast the message using our include/exclude constraints
        yield 'broadcast' => [$msg, $include, $exclude];
    }

    public function onClose($clientId, $code, $reason) {
        $msg = json_encode(['type' => 'count', 'data' => --$this->clientCount]);

        // Broadcast the current user count to all connected users
        yield 'broadcast' => $msg;
    }
}
