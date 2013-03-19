<?php

namespace Aerys\Io\BodyWriters;

use Aerys\Io\ResourceException;

class Stream extends BodyWriter {
    
    private $destination;
    private $body;
    
    private $writeBuffer = '';
    
    function __construct($destination, \Iterator $body) {
        $this->destination = $destination;
        $this->body = $body;
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
            return !$this->body->valid();
            
        } elseif ($bytesWritten) {
            
            $this->writeBuffer = substr($this->writeBuffer, $bytesWritten);
            return FALSE;
            
        } elseif (!is_resource($this->destination)) {
            
            throw new ResourceException(
                'Failed writing to destination stream resource'
            );
            
        }
    }
    
}

