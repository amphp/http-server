<?php

namespace Aerys\Internal;

use Amp\Struct;

/**
 * Used in Http2Driver.
 */
class Http2Stream {
    use Struct;

    const DEFAULT_INITIAL_WINDOW_SIZE = 65536;

    public $end = false;
    public $window;
    public $buffer = "";

    public function __construct(int $size = self::DEFAULT_INITIAL_WINDOW_SIZE) {
        $this->window = $size;
    }
}
