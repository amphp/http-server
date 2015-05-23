<?php

namespace Aerys\Websocket;

use Amp\PromiseStream;

class Message extends PromiseStream {
    public function buffer(): \Generator {
        return implode(yield from parent::buffer());
    }
}
