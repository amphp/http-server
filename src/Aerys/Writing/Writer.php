<?php

namespace Aerys\Writing;

use Aerys\ResourceException;

class Writer {
    
    protected $destination;
    protected $buffer = '';
    protected $bufferLen = 0;
    protected $granularity = 262144;
    
    function __construct($destination, $buffer) {
        $this->destination = $destination;
        $this->bufferData($buffer);
    }
    
    protected function bufferData($buffer) {
        $this->buffer .= $buffer;
        $this->bufferLen += ($buffer || $buffer === '0') ? strlen($buffer) : 0;
    }
    
    function write() {
        if (!$this->bufferLen) {
            return TRUE;
        }
        
        $bytesWritten = @fwrite($this->destination, $this->buffer, $this->granularity);
        
        if ($bytesWritten === $this->bufferLen) {
            $this->buffer = NULL;
            $this->bufferLen = NULL;
            $result = TRUE;
        } elseif ($bytesWritten) {
            $this->buffer = substr($this->buffer, $bytesWritten);
            $this->bufferLen -= $bytesWritten;
            $result = FALSE;
        } elseif (is_resource($this->destination)) {
            $result = FALSE;
        } else {
            throw new ResourceException;
        }
        
        return $result;
    }
    
    function setGranularity($bytes) {
        $this->granularity = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'default' => 262144,
            'min_range' => 1
        ]]);
    }
    
}

