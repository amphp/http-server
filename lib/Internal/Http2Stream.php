<?php

namespace Aerys\Internal;

use Aerys\Http2Driver;
use Amp\Struct;

/**
 * Used in Http2Driver.
 */
class Http2Stream {
    use Struct;

    public $end = false;
    public $window;
    public $buffer = "";

    public function __construct(int $size = Http2Driver::DEFAULT_WINDOW_SIZE) {
        $this->window = $size;
    }
}
