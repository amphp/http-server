<?php

namespace Aerys\Http\BodyWriters;

abstract class BodyWriter {

    protected $granularity = 8192;
    
    function setGranularity($bytes) {
        $this->granularity = (int) $bytes;
    }
    
    abstract function write();
}

