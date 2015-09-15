<?php

namespace Aerys;

final class NullBody extends Body {
    public function __construct() {}

    public function when(callable $func, $data = null) {
        \call_user_func($func, null, "", $data);
        return $this;
    }

    public function watch(callable $func, $data = null) {
        return $this;
    }
}