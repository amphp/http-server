<?php

namespace Aerys\Http\Io;

class ByteRangeBody {
    
    private $resource;
    private $startPos;
    private $endPos;
    
    function __construct($resource, $startPos, $endPos) {
        $this->resource = $resource;
        $this->startPos = $startPos;
        $this->endPos = $endPos;
    }
    
    function getResource() {
        return $this->resource;
    }
    
    function getStartPos() {
        return $this->startPos;
    }
    
    function getEndPos() {
        return $this->endPos;
    }
    
}

