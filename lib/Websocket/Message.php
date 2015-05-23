<?php

namespace Aerys\Websocket;

use Amp\PromiseStream;

class Message extends PromiseStream implements Streamable {
    public function buffer(): \Generator {
        return implode(yield from parent::buffer());
    }
}
