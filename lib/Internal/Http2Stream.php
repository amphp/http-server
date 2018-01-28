<?php

namespace Aerys\Internal;

use Aerys\Http2Driver;
use Amp\Struct;

/**
 * Used in Http2Driver.
 */
class Http2Stream {
    use Struct;

    const OPEN = 0;
    const RESERVED = 0b0001;
    const REMOTE_CLOSED = 0b0010;
    const LOCAL_CLOSED = 0b0100;
    const CLOSED = 0b0110;

    public $window;
    public $buffer = "";
    public $state;

    public function __construct(int $size = Http2Driver::DEFAULT_WINDOW_SIZE, int $state = self::OPEN) {
        $this->window = $size;
        $this->state = $state;
    }
}
