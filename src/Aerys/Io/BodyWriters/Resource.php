<?php

namespace Aerys\Io\BodyWriters;

use Aerys\Io\ResourceException;

static $myCount = 0;

class Resource extends BodyWriter {
    
    private $destination;
    private $source;
    private $sourcePos;
    private $contentLength;
    private $bytesRemaining;
    private $maxChunkSize = 262144;
    
    function __construct($destination, $source, $contentLength) {
        $this->destination = $destination;
        $this->source = $source;
        $this->sourcePos = 0;
        $this->contentLength = $contentLength;
        $this->bytesRemaining = $contentLength;
    }
    
    /**
     * The stream is manually fseek'd on each write to allow caching of file descriptors across
     * many concurrent responses
     */
    function write() {
        $byteWriteLimit = ($this->bytesRemaining > $this->maxChunkSize)
            ? $this->maxChunkSize
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
        
        if (!$this->bytesRemaining) {
            return TRUE;
        } elseif ($bytesWritten) {
            return FALSE;
        } elseif (!(is_resource($this->destination) && is_resource($this->source))) {
            throw new ResourceException(
                'Failed copying source stream to destination'
            );
        }
    }
    
}

