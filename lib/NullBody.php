<?php

namespace Aerys;

use Amp\Subscriber;

final class NullBody extends Body {
    public function __construct() { /* override */ }

    public function when(callable $func, $data = null) {
        \call_user_func($func, null, "", $data);
        return $this;
    }

    public function subscribe(callable $func): Subscriber {
        return new Subscriber(null, function() {});
    }
    
    public function __destruct() { /* override */ }
}