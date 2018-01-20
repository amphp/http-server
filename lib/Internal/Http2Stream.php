<?php

namespace Aerys\Internal;

use Amp\Struct;

/**
 * Used in Http2Driver.
 */
class Http2Stream {
    use Struct;

    public $end = false;
    public $window = 65536;
    public $buffer = "";
}
