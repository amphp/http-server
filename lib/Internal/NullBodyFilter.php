<?php

namespace Aerys\Internal;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\Coroutine;
use Amp\Promise;

class NullBodyFilter implements Filter {
    public function filter(Request $request, Response $response): Response {
        Promise\rethrow(new Coroutine($this->consume($response->body)));
        unset($response->headers["transfer-encoding"]);
        $response->body = new InMemoryStream;
    }

    private function consume(InputStream $stream): \Generator {
        try {
            while (null !== yield $stream->read()) {
                // Discard unread bytes from message.
            }
        } catch (\Throwable $exception) {
            // Body was being thrown away anyway, ignore errors.
        }
    }
}
