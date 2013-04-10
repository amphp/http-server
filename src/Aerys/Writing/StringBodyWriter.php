<?php

namespace Aerys\Writing;

class StringBodyWriter implements BodyWriter {
    
    private $destination;
    private $body;
    private $contentLength;
    private $granularity = 131072;
    
    private $totalBytesWritten = 0;
    
    function __construct($destination, $body, $contentLength) {
        $this->destination = $destination;
        $this->body = $body;
        $this->contentLength = $contentLength;
    }
    
    function setGranularity($bytes) {
        $this->granularity = (int) $bytes;
    }
    
    function write() {
        $bytesWritten = @fwrite($this->destination, $this->body, $this->granularity);
        
        $this->totalBytesWritten += $bytesWritten;
        
        if ($this->totalBytesWritten == $this->contentLength) {
            $result = TRUE;
        } elseif ($bytesWritten) {
            $this->body = substr($this->body, $bytesWritten);
            $result = FALSE;
        } elseif (!is_resource($this->destination)) {
            throw new ResourceWriteException;
        }
        
        return $result;
    }
}

