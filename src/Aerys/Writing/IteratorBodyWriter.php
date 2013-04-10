<?php

namespace Aerys\Writing;

class IteratorBodyWriter implements BodyWriter {
    
    private $destination;
    private $body;
    private $granularity = 131072;
    private $writeBuffer = '';
    
    function __construct($destination, \Iterator $body) {
        $this->destination = $destination;
        $this->body = $body;
    }
    
    function setGranularity($bytes) {
        $this->granularity = (int) $bytes;
    }
    
    function write() {
        $this->writeBuffer .= $this->body->current();
        $this->body->next();
        
        if (!$this->writeBuffer && $this->writeBuffer !== '0') {
            return FALSE;
        }
        
        $bytesWritten = @fwrite($this->destination, $this->writeBuffer, $this->granularity);
        
        if ($bytesWritten && $bytesWritten == strlen($this->writeBuffer)) {
            $this->writeBuffer = NULL;
            $result = !$this->body->valid();
        } elseif ($bytesWritten) {
            $this->writeBuffer = substr($this->writeBuffer, $bytesWritten);
            $result = FALSE;
        } elseif (!is_resource($this->destination)) {
            throw new ResourceWriteException;
        }
        
        return $result;
    }
    
}

