<?php

namespace Aerys\Writing;

class IteratorWriter extends Writer {
    
    private $body;
    
    function __construct($destination, $rawHeaders, \Iterator $body) {
        parent::__construct($destination, $rawHeaders);
        $this->body = $body;
    }
    
    function write() {
        $nextChunk = $this->body->current();
        $this->body->next();
        
        if ($nextChunk || $nextChunk === '0') {
            $this->bufferData($nextChunk);
        }
        
        return parent::write() ? !$this->body->valid() : FALSE;
    }
    
}

