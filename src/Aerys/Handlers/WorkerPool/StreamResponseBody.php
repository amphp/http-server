<?php

namespace Aerys\Handlers\WorkerPool;

class StreamResponseBody implements \Iterator {
    
    private $chunks = [];
    private $isValid = TRUE;
    
    function markComplete() {
        $this->isValid = FALSE;
    }
    
    function addData($partialResult) {
        $this->chunks[] = $partialResult;
    }

    function current() {
        return $this->chunks ? array_shift($this->chunks) : NULL;
    }

    function valid() {
        return $this->chunks || $this->isValid;
    }

    function key() {}
    function next() {}
    function rewind() {}
    
}
