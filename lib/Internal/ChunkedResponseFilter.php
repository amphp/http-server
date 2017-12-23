<?php

namespace Aerys\Internal;

use Amp\ByteStream\IteratorStream;
use Amp\Producer;

class ChunkedResponseFilter implements ResponseFilter {
    const DEFAULT_BUFFER_SIZE = 8192;

    public function filterResponse(Request $ireq, Response $ires) {
        if ($ires->status < 200) {
            return;
        }

        if (isset($ires->headers["content-length"])) {
            return;
        }

        if (empty($ires->headers["transfer-encoding"])) {
            return;
        }

        if (!\in_array("chunked", $ires->headers["transfer-encoding"])) {
            return;
        }

        $body = $ires->body;
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

        $ires->body = $stream;
    }
}
