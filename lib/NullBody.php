<?php

namespace Aerys;

use Amp\Promise;

class NullBody extends Body {
    public function __construct() {
        // We need to override the parent constructor
    }
    public function stream(): \Generator {
        yield new Success("");
    }
    public function buffer(): Promise {
        return new Success("");
    }
}
