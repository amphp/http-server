<?php

namespace Aerys\Root;

class UvStat extends Stat {
    public $loop;
    public $handle;
    public function __destruct() {
        if ($this->handle) {
            uv_fs_close($this->loop, $this->handle, [$this, "noop"]);
        }
    }
    public function noop() {}
}
