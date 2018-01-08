<?php

namespace Aerys\Internal;

use Amp\Struct;

/**
 * Used in Aerys\Root.
 */
final class ByteRange {
    use Struct;

    public $ranges;
    public $boundary;
    public $contentType;
}
