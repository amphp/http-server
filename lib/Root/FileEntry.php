<?php

namespace Aerys\Root;

class FileEntry extends \Amp\Struct {
    public $path;
    public $handle;
    public $size;
    public $mtime;
    public $inode;
    public $etag;
    public $buffer;
}
