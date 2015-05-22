<?php

namespace Aerys;

use Amp\PromiseStream;

class WebsocketMessage extends PromiseStream implements Streamable {
    public function buffer(): \Generator {
        return implode(yield from parent::buffer());
    }
}
