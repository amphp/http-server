<?php

namespace Aerys\Parsing;

class TempEntityWriter {
    
    private $path;
    private $resource;
    private $buffer = '';
    
    /**
     * @throws ResourceException On resource open failure
     */
    function __construct($path) {
        if (!$this->resource = fopen($path, 'ab+')) {
            throw new ResourceException;
        }
        stream_set_blocking($this->resource, FALSE);
        $this->path = $path;
    }
    
    /**
     * Clean up after ourselves when the object is destroyed
     */
    function __destruct() {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
        
        unlink($this->path);
    }
    
    /**
     * @param string $data The info we want to write
     * @throws ResourceException On resource write failure
     * @return bool Returns TRUE if all data has been written, FALSE otherwise
     */
    function write($data = NULL) {
        if ($data !== NULL) {
            $this->buffer .= $data;
        } elseif ($this->buffer === '') {
            return TRUE;
        }
        
        $bytesToWrite = strlen($this->buffer);
        $bytesWritten = fwrite($this->resource, $this->buffer, $bytesToWrite);
        
        if ($bytesWritten == $bytesToWrite) {
            $this->buffer = '';
            return TRUE; // woot!
        } elseif ($bytesWritten) {
            $this->buffer = substr($this->buffer, $bytesWritten);
            return FALSE;
        } elseif (!$bytesWritten && is_resource($this->resource)) {
            return FALSE; // try again next time
        } elseif (!$bytesWritten) {
            throw new ResourceException;
        }
    }
    
    function getResource() {
        return $this->resource;
    }
    
}
