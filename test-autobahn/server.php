<?php

// Ignore this if-statement, it serves only to prevent running this file directly.
if (!class_exists(Aerys\Process::class, false)) {
    echo "This file is not supposed to be invoked directly. To run it, use `php bin/aerys -c demo.php`.\n";
    exit(1);
}

use Aerys\{ Host, Request, Response, Websocket, function root, function router, function websocket };

/* --- http://localhost:9001/ ------------------------------------------------------------------- */

$websocket = websocket(new class implements Websocket {
    /** @var \Aerys\Websocket\Endpoint */
    private $endpoint;

    public function onStart(Websocket\Endpoint $endpoint) {
        $this->endpoint = $endpoint;
    }

    public function onHandshake(Request $request, Response $response) { }
    public function onOpen(int $clientId, $handshakeData) { }

    public function onData(int $clientId, Websocket\Message $msg) {
        if ($msg->isBinary()) {
            $this->endpoint->broadcastBinary(yield $msg);
        } else {
            $this->endpoint->broadcast(yield $msg);
        }
    }

    public function onClose(int $clientId, int $code, string $reason) { }
    public function onStop() { }
}, [
    "maxBytesPerMinute" => PHP_INT_MAX,
    "maxFrameSize" => PHP_INT_MAX,
    "maxFramesPerSecond" => PHP_INT_MAX,
    "maxMsgSize" => PHP_INT_MAX,
    "validateUtf8" => true
]);

$router = router()->route("GET", "/ws", $websocket);

// If none of our routes match try to serve a static file
$root = root($docrootPath = __DIR__);

return (new Host)->expose("127.0.0.1", 9001)->use($router);
