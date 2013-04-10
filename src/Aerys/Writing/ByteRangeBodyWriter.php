<?php

namespace Aerys\Writing;

class ByteRangeBodyWriter implements BodyWriter {
    
    private $destination;
    private $source;
    private $sourcePos;
    private $totalBytesToWrite;
    private $totalBytesWritten = 0;
    private $granularity = 131072;
    
    function setGranularity($bytes) {
        $this->granularity = (int) $bytes;
    }
    
    function __construct($destination, ByteRangeBody $body) {
        $this->destination = $destination;
        $this->source = $body->getResource();
        
        $startPos = $body->getStartPos();
        $this->sourcePos = $startPos;
        fseek($this->source, $startPos);
        
        $endPos = $body->getEndPos();
        $this->totalBytesToWrite = $endPos - $startPos;
    }
    
    function write() {
        $remainingBytes = $this->totalBytesToWrite - $this->totalBytesWritten;
        $maxWriteSize = ($remainingBytes > $this->granularity) ? $this->granularity : $remainingBytes;
        
        $bytesWritten = @stream_copy_to_stream(
            $this->source,
            $this->destination,
            $maxWriteSize,
            $this->sourcePos
        );
        
        $this->sourcePos += $bytesWritten;
        $this->totalBytesWritten += $bytesWritten;
        
        if ($bytesWritten && ($this->totalBytesWritten == $this->totalBytesToWrite)) {
            $result = TRUE;
        } elseif (!$bytesWritten && !(is_resource($this->destination) && is_resource($this->source))) {
            throw new ResourceWriteException;
        } else {
            $result = FALSE;
        }
        
        return $result;
    }
    
}

