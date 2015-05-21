<?php

namespace Aerys;

use Amp\{ Streamable, PromiseStream, Success };

final class BodyNull extends PromiseStream implements Body {
    public function __construct() {}
    public function stream(): \Generator {
        yield new Success;
    }
    public function buffer(): \Generator {
        yield;
        return;
    }
}
