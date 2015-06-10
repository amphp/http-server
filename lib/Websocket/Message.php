<?php

namespace Aerys\Websocket;

use Amp\PromiseStream;

class Message extends PromiseStream {
    public function buffer(): \Generator {
        $buffer = [];
        foreach ($this->stream() as $promise) {
            $buffer[] = yield $promise;
        }
        array_pop($buffer);

        yield "return" => implode($buffer);
    }
}