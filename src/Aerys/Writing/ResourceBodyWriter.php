<?php

namespace Aerys\Writing;

class ResourceBodyWriter implements BodyWriter {
    
    private $destination;
    private $source;
    private $sourcePos;
    private $contentLength;
    private $bytesRemaining;
    private $granularity = 131072;
    
    function __construct($destination, $source, $contentLength) {
        $this->destination = $destination;
        $this->source = $source;
        $this->sourcePos = 0;
        $this->contentLength = $contentLength;
        $this->bytesRemaining = $contentLength;
    }
    
    function setGranularity($bytes) {
        $this->granularity = (int) $bytes;
    }
    
    /**
     * The stream is manually fseek'd on each write to allow caching of file descriptors across
     * many concurrent responses
     */
    function write() {
        $byteWriteLimit = ($this->bytesRemaining > $this->granularity)
            ? $this->granularity
            : $this->bytesRemaining;
        
        $offsetPosition = $this->contentLength - $this->bytesRemaining;
        
        fseek($this->source, $this->sourcePos);
        
        $bytesWritten = @stream_copy_to_stream(
            $this->source,
            $this->destination,
            $byteWriteLimit,
            $offsetPosition
        );
        
        $this->sourcePos += $bytesWritten;
        
        $this->bytesRemaining -= $bytesWritten;
        
        if ($this->bytesRemaining <= 0) {
            $result = TRUE;
        } elseif ($bytesWritten) {
            $result = FALSE;
        } elseif (!(is_resource($this->destination) && is_resource($this->source))) {
            throw new ResourceWriteException;
        } else {
            $result = FALSE;
        }
        
        return $result;
    }
    
}

