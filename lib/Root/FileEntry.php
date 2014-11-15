<?php

namespace Aerys\Root;

use Aerys\Struct;

class FileEntry extends Struct {
    public $path;
    public $handle;
    public $size;
    public $mtime;
    public $inode;
    public $etag;
    public $buffer;
}
