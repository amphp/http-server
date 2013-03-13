<?php

namespace Aerys\Ws\Io;

class Sequence extends Stream implements \Countable, \Iterator {
    
    private $sequence;
    private $currentCache;
    private $buffer;
    
    function __construct(\Traversable $stream, $payloadType) {
        parent::__construct($payloadType);
        $this->sequence = $stream;
    }
    
    function count() {
        return $this->sequence->count();
    }
    
    function rewind() {
        $this->currentCache = NULL;
        return $this->sequence->rewind();
    }
    
    function valid() {
        return $this->sequence->valid();
    }
    
    function key() {
        return $this->sequence->key();
    }
    
    function current() {
        if (isset($this->currentCache)) {
            return $this->currentCache;
        }
        
        $current = $this->sequence->current();
        if (!($current || $current === '0')) {
            return NULL;
        }
        
        $this->buffer .= $current;
        $bufferSize = strlen($this->buffer);
        
        if (!$this->autoFrameSize || $this->autoFrameSize > $bufferSize) {
            $this->currentCache = $this->buffer;
            $this->buffer = NULL;
        } else {
            $this->currentCache = substr($this->buffer, 0, $this->autoFrameSize);
            $this->buffer = substr($this->buffer, $this->autoFrameSize);
        }
        
        return $this->currentCache;
    }

    function next() {
        $this->currentCache = NULL;
        return $this->sequence->next();
    }
    
}
