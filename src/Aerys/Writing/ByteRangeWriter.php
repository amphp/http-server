<?php

namespace Aerys\Writing;

class ByteRangeWriter extends StreamWriter {
    
    function __construct($destination, $rawHeaders, ByteRangeBody $body) {
        $this->destination = $destination;
        $this->bufferData($rawHeaders);
        
        $startPos = $body->getStartPosition();
        $endPos = $body->getEndPosition();
        
        $this->body = $body->getResource();
        $this->bodyPos = $startPos;
        $this->bodyLength = $this->bytesRemaining = ($endPos - $startPos);
    }
    
}

