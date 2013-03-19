<?php

namespace Aerys\Io\BodyWriters;

use Aerys\Io\ResourceException;

static $myCount = 0;

class Resource extends BodyWriter {
    
    private $destination;
    private $source;
    private $sourcePos;
    private $contentLength;
    private $bytesLeftToWrite;
    
    function __construct($destination, $source, $contentLength) {
        $this->destination = $destination;
        $this->source = $source;
        $this->sourcePos = 0;
        $this->contentLength = $contentLength;
        $this->bytesLeftToWrite = $contentLength;
    }
    
    /**
     * The stream is manually fseek'd on each write to allow caching of file descriptors across
     * many concurrent responses
     */
    function write() {
        $byteWriteLimit = ($this->bytesLeftToWrite > $this->granularity)
            ? $this->granularity
            : $this->bytesLeftToWrite;
        
        $offsetPosition = $this->contentLength - $this->bytesLeftToWrite;
        
        fseek($this->source, $this->sourcePos);
        
        $bytesWritten = @stream_copy_to_stream(
            $this->source,
            $this->destination,
            $byteWriteLimit,
            $offsetPosition
        );
        
        $this->sourcePos += $bytesWritten;
        
        $this->bytesLeftToWrite -= $bytesWritten;
        
        if (!$this->bytesLeftToWrite) {
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

