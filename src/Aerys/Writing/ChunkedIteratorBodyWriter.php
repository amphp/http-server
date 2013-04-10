<?php

namespace Aerys\Writing;

class ChunkedIteratorBodyWriter implements BodyWriter {
    
    const CHUNK_DELIMITER = "\r\n";
    const FINAL_CHUNK = "0\r\n\r\n";
    
    private $destination;
    private $body;
    private $writeBuffer = '';
    private $remainingChunkBytes;
    private $hasBufferedFinalChunk = FALSE;
    private $granularity = 131072;
    
    function __construct($destination, \Iterator $body) {
        $this->destination = $destination;
        $this->body = $body;
    }
    
    function setGranularity($bytes) {
        $this->granularity = (int) $bytes;
    }
    
    function write() {
        if (!$this->writeBuffer && !$this->generateNextChunk()) {
            return FALSE;
        }
        
        $bytesWritten = @fwrite($this->destination, $this->writeBuffer, $this->granularity);
        
        if ($bytesWritten && $bytesWritten == $this->remainingChunkBytes) {
        
            $this->writeBuffer = NULL;
            $this->remainingChunkBytes = 0;
            $result = $this->hasBufferedFinalChunk;
            
        } elseif ($bytesWritten) {
        
            $this->writeBuffer = substr($this->writeBuffer, $bytesWritten);
            $this->remainingChunkBytes -= $bytesWritten;
            $result = FALSE;
            
        } elseif (!is_resource($this->destination)) {
            throw new ResourceWriteException;
        }
        
        return $result;
    }
    
    private function generateNextChunk() {
        if ($this->body->valid()) {
            $nextChunkData = $this->body->current();
            $this->body->next();
            
            if (!$nextChunkData && $nextChunkData !== '0') {
                return FALSE;
            }
            
            $chunkSize = strlen($nextChunkData);
            $this->writeBuffer = dechex($chunkSize) . self::CHUNK_DELIMITER . $nextChunkData . self::CHUNK_DELIMITER;
        } else {
            $this->writeBuffer = self::FINAL_CHUNK;
            $this->hasBufferedFinalChunk = TRUE;
        }
        
        $this->remainingChunkBytes = strlen($this->writeBuffer);
        
        return TRUE;
    }
    
}

