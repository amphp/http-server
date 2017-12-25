<?php

namespace Aerys\Internal;

use Aerys\Middleware;
use Aerys\Request;
use Aerys\Response;
use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\Coroutine;
use Amp\Promise;

class NullBodyFilter implements Middleware {
    public function process(Request $request, Response $response): Response {
        Promise\rethrow(new Coroutine($this->consume($response->getBody())));
        $response->removeHeader("transfer-encoding");
        $response->setBody(new InMemoryStream);
        return $response;
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
