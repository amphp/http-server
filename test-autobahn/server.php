<?php

// Ignore this if-statement, it serves only to prevent running this file directly.
if (!class_exists(Aerys\Process::class, false)) {
    echo "This file is not supposed to be invoked directly.\n";
    exit(1);
}

use Aerys\Request;
use Aerys\Websocket;

return function (Aerys\Logger $logger) {
    /* --- http://localhost:9001/ ------------------------------------------------------------------- */

    $websocket = Aerys\websocket(new class implements Websocket\Websocket {
        /** @var \Aerys\Websocket\Endpoint */
        private $endpoint;

        public function onStart(Websocket\Endpoint $endpoint) {
            $this->endpoint = $endpoint;
        }

        public function onHandshake(Request $request) { }

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
    }, $logger, [
        "maxBytesPerMinute"  => PHP_INT_MAX,
        "maxFrameSize"       => PHP_INT_MAX,
        "maxFramesPerSecond" => PHP_INT_MAX,
        "maxMsgSize"         => PHP_INT_MAX,
        "validateUtf8"       => true
    ]);

    return (new Aerys\Server)->expose("127.0.0.1", 9001)->use($websocket);
};
