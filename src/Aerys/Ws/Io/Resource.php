<?php

namespace Aerys\Ws\Io;

class Resource extends Stream implements \Countable, \SeekableIterator {
    
    private $resource;
    
    function __construct($resource, $payloadType) {
        parent::__construct($payloadType);
        
        if (is_resource($resource)) {
            $this->resource = $resource;
        } else {
            throw new \InvalidArgumentException(
                'Resource::__construct requires a valid stream resource at Argument 1'
            );
        }
    }
    
    function count() {
        $currentPos = $this->key();
        $this->seek(0, SEEK_END);
        $endPos = $this->key();
        $this->seek($currentPos);
        
        return $endPos;
    }
    
    function seek($position, $whence = SEEK_SET) {
        if (!@fseek($this->resource, $position, $whence)) {
            $this->keyCache = ftell($this->resource);
            $this->currentCache = NULL;
        } else {
            throw new StreamException(
                'Failed seeking on frame resource'
            );
        }
    }
    
    function rewind() {
        if (!@rewind($this->resource)) {
            throw new StreamException(
                'Failed seeking on frame resource'
            );
        }
    }
    
    function valid() {
        return !feof($this->resource);
    }
    
    function key() {
        if (isset($this->keyCache)) {
            return $this->keyCache;
        } elseif (FALSE !== ($this->keyCache = @ftell($this->resource))) {
            return $this->keyCache;
        } else {
            throw new StreamException(
                'Failed stat check on frame resource'
            );
        }
    }
    
    function current() {
        if (isset($this->currentCache)) {
            return $this->currentCache;
        }
        
        $this->currentCache = $this->autoFrameSize
            ? @fread($this->resource, $this->autoFrameSize)
            : @stream_get_contents($this->resource);
        
        if (FALSE !== $this->currentCache) {
            return $this->currentCache;
        } else {
            $this->currentCache = NULL;
            throw new StreamException(
                'Failed reading from frame resource'
            );
        }
    }

    function next() {
        $this->currentCache = NULL;
        $this->keyCache = NULL;
    }
    
}

