<?php declare(strict_types = 1);

namespace Aerys;

final class NullBody extends Body {
    public function __construct() { /* override */ }

    public function when(callable $func, $data = null) {
        $func(null, "", $data);
        return $this;
    }

    public function subscribe(callable $func) { }
    
    public function __destruct() { /* override */ }
}