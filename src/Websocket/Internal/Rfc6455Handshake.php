<?php

namespace Amp\Http\Server\Websocket\Internal;

use Amp\ByteStream\InMemoryStream;
use Amp\Http\Server\Response;
use Amp\Http\Status;

class Rfc6455Handshake extends Response {
    const ACCEPT_CONCAT = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";

    /**
     * @param string $acceptKey The client request's SEC-WEBSOCKET-KEY header value
     */
    public function __construct(string $acceptKey) {
        $concatKeyStr = $acceptKey . self::ACCEPT_CONCAT;
        $secWebSocketAccept = base64_encode(sha1($concatKeyStr, true));

        $headers = [
            "Connection" => "upgrade",
            "Upgrade" => "websocket",
            "Sec-WebSocket-Accept" => $secWebSocketAccept,
        ];

        parent::__construct(new InMemoryStream, $headers, Status::SWITCHING_PROTOCOLS);
    }
}
