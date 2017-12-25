<?php

namespace Aerys\Internal;

use Aerys\Middleware;
use Aerys\Request;
use Aerys\Response;
use Amp\ByteStream\IteratorStream;
use Amp\Producer;

class ChunkedMiddleware implements Middleware {
    const DEFAULT_BUFFER_SIZE = 8192;

    public function process(Request $request, Response $response): Response {
        $headers = $response->getHeaders();

        if (isset($headers["content-length"])) {
            return $response;
        }

        if (empty($headers["transfer-encoding"])) {
            return $response;
        }

        if (!\in_array("chunked", $headers["transfer-encoding"])) {
            return $response;
        }

        $body = $response->getBody();
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

        $response->setBody($stream);

        return $response;
    }
}
