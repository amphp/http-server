<?php

// Ignore this if-statement, it serves only to prevent running this file directly.
if (!class_exists(Aerys\Process::class, false)) {
    echo "This file is not supposed to be invoked directly.\n";
    exit(1);
}

use Aerys\Request;
use Aerys\Response;
use Aerys\Websocket;

return function () {
    /* --- http://localhost:9001/ ------------------------------------------------------------------- */

    $websocket = new Websocket\Websocket(new class implements Websocket\Application {
        /** @var \Aerys\Websocket\Endpoint */
        private $endpoint;

        public function onStart(Websocket\Endpoint $endpoint) {
            $this->endpoint = $endpoint;
        }

        public function onHandshake(Request $request, Response $response) {
            return $response;
        }

        public function onOpen(int $clientId, Request $request) { }

        public function onData(int $clientId, Websocket\Message $message) {
            if ($message->isBinary()) {
                $this->endpoint->broadcastBinary(yield $message->buffer());
            } else {
                $this->endpoint->broadcast(yield $message->buffer());
            }
        }

        public function onClose(int $clientId, int $code, string $reason) { }

        public function onStop() { }
    });

    $websocket->setMaxBytesPerMinute(PHP_INT_MAX);
    $websocket->setMaxFrameSize(PHP_INT_MAX);
    $websocket->setMaxFramesPerSecond(PHP_INT_MAX);
    $websocket->setMessageSizeLimit(PHP_INT_MAX);
    $websocket->validateUtf8(true);

    $server = new Aerys\Server($websocket);
    $server->expose("127.0.0.1", 9001);

    return $server;
};
