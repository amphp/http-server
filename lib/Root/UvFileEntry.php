<?php

namespace Aerys\Root;

class UvFileEntry extends FileEntry {
    public $uvLoop;
    public function __destruct() {
        uv_fs_close($this->uvLoop, $this->handle, function(){});
    }
}
