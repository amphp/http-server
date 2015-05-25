<?php

namespace Aerys\Root;

use Amp\Struct;

class Stat {
    use Struct;
    public $exists;
    public $path;
    public $size;
    public $mtime;
    public $inode;
    public $buffer;
    public $etag;
    public $handle;
}
