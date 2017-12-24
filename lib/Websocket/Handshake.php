<?php

namespace Aerys\Websocket;

use Aerys\Response;
use Amp\ByteStream\InMemoryStream;

class Handshake extends Response {
    const ACCEPT_CONCAT = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";

    /**
     * @param \Aerys\Response $response The server Response to wrap for the handshake
     * @param string $acceptKey The client request's SEC-WEBSOCKET-KEY header value
     */
    public function __construct(string $acceptKey, int $code = 101) {
        if (!($code === 101 || $code >= 300)) {
            throw new \Error(
                "Invalid websocket handshake status ({$code}); 101 or 300-599 required"
            );
        }

        $concatKeyStr = $acceptKey . self::ACCEPT_CONCAT;
        $secWebSocketAccept = base64_encode(sha1($concatKeyStr, true));

        $headers = [
            "Connection" => "upgrade",
            "Upgrade" => "websocket",
            "Sec-WebSocket-Accept" => $secWebSocketAccept,
        ];

        parent::__construct(new InMemoryStream, $headers, $code);
    }
}
