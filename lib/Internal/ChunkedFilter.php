<?php

namespace Aerys\Internal;

use Amp\ByteStream\IteratorStream;
use Amp\Producer;

class ChunkedFilter implements Filter {
    const DEFAULT_BUFFER_SIZE = 8192;

    public function filter(Request $request, Response $response) {
        if (isset($response->headers["content-length"])) {
            return;
        }

        if ($request->protocol === "1.0") {
            $response->headers["connection"] = ["close"]; // Cannot chunk, close instead.
            return;
        }

        if (!isset($response->headers["connection"]) || !\in_array("keep-alive", $response->headers["connection"])) {
            return;
        }

        $response->headers["transfer-encoding"] = ["chunked"];

        $body = $response->body;
        $stream = new IteratorStream(new Producer(function (callable $emit) use ($body) {
            $bodyBuffer = '';
            $bufferSize = $ireq->client->options->chunkBufferSize ?? self::DEFAULT_BUFFER_SIZE;

            while (null !== $chunk = yield $body->read()) {
                $bodyBuffer .= $chunk;
                if ($bufferSize < $length = \strlen($bodyBuffer)) {
                    yield $emit(\sprintf("%x\r\n%s\r\n", $length, $bodyBuffer));
                    $bodyBuffer = '';
                }
            }

            if ($bodyBuffer !== '') {
                $emit(\sprintf("%x\r\n%s\r\n0\r\n\r\n", \strlen($bodyBuffer), $bodyBuffer));
            } else {
                $emit("0\r\n\r\n");
            }
        }));

        $response->body = $stream;
    }
}
