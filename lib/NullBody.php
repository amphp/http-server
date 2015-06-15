<?php

namespace Aerys;

use Amp\Promise;

final class NullBody extends Body implements Promise {
    public function __construct() {}
    public function stream(): \Generator {
        yield new Success;
    }
    public function when(callable $func, $data = null) {
        \call_user_func($func, null, null, $data);
    }
    public function watch(callable $func, $data = null) {
        // does nothing.
    }
}
