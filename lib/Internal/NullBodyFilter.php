<?php

namespace Aerys\Internal;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\Coroutine;
use Amp\Promise;

class NullBodyFilter implements ResponseFilter {
    public function filterResponse(Request $ireq, Response $ires) {
        Promise\rethrow(new Coroutine($this->consume($ires->body)));
        unset($ires->headers["transfer-encoding"]);
        $ires->body = new InMemoryStream;
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
