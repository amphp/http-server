<?php

namespace Aerys\Http\BodyWriters;

use Aerys\Http\ResourceException,
    Aerys\Http\ByteRangeBody;

class ByteRange extends BodyWriter {
    
    private $destination;
    private $source;
    private $sourcePos;
    private $totalBytesToWrite;
    private $totalBytesWritten = 0;
    
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

