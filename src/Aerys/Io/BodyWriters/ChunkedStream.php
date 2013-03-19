<?php

namespace Aerys\Io\BodyWriters;

use Aerys\Io\ResourceException;

class ChunkedStream extends BodyWriter {
    
    const CHUNK_DELIMITER = "\r\n";
    const FINAL_CHUNK = "0\r\n\r\n";
    
    private $destination;
    private $body;
    private $writeBuffer = '';
    private $remainingChunkBytes;
    private $hasBufferedFinalChunk = FALSE;
    
    function __construct($destination, \Iterator $body) {
        $this->destination = $destination;
        $this->body = $body;
    }
    
    function write() {
        if (!$this->writeBuffer && !$this->generateNextChunk()) {
            return FALSE;
        }
        
        $bytesWritten = @fwrite($this->destination, $this->writeBuffer, $this->granularity);
        
        if ($bytesWritten && $bytesWritten == $this->remainingChunkBytes) {
        
            $this->writeBuffer = NULL;
            $this->remainingChunkBytes = 0;
            return $this->hasBufferedFinalChunk;
            
        } elseif ($bytesWritten) {
        
            $this->writeBuffer = substr($this->writeBuffer, $bytesWritten);
            $this->remainingChunkBytes -= $bytesWritten;
            return FALSE;
            
        } elseif (!is_resource($this->destination)) {
            throw new ResourceException(
                'Failed writing to destination stream resource'
            );
        }
    }
    
    private function generateNextChunk() {
        if ($this->body->valid()) {
            $nextChunkData = $this->body->current();
            if (!$nextChunkData && $nextChunkData !== '0') {
                return FALSE;
            }
            
            $this->body->next();
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

