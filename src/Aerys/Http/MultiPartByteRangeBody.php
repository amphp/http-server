<?php

namespace Aerys\Http;

class MultiPartByteRangeBody implements \Iterator {
    
    private $resource;
    private $ranges;
    private $boundary;
    private $contentType;
    
    function __construct($resource, array $ranges, $boundary, $contentType, $contentLength) {
        $this->resource = $resource;
        $this->ranges = $ranges;
        $this->boundary = $boundary;
        $this->contentType = $contentType;
        $this->contentLength = $contentLength;
    }
    
    function getResource() {
        return $this->resource;
    }
    
    function getBoundary() {
        return $this->boundary;
    }
    
    function getContentType() {
        return $this->contentType;
    }
    
    function getContentLength() {
        return $this->contentLength;
    }
    
    function current() {
        return current($this->ranges);
    }

    function key() {
        return key($this->ranges);
    }

    function next() {
        return next($this->ranges);
    }

    function rewind() {
        return reset($this->ranges);
    }

    function valid() {
        return key($this->ranges) !== NULL;
    }
    
}

