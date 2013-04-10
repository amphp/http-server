<?php

namespace Aerys\Writing;

class BodyWriterFactory {
    
    function make($destinationStream, $body, $protocol, $contentLength) {
        if ($contentLength && is_string($body)) {
            $bodyWriter = new StringBodyWriter($destinationStream, $body, $contentLength);
        } elseif ($contentLength && is_resource($body)) {
            $bodyWriter = new ResourceBodyWriter($destinationStream, $body, $contentLength);
        } elseif ($body instanceof ByteRangeBody) {
            $bodyWriter = new ByteRangeBodyWriter($destinationStream, $body);
        } elseif ($body instanceof MultiPartByteRangeBody) {
            $bodyWriter = new MultiPartByteRangeBodyWriter($destinationStream, $body);
        } elseif ($body instanceof \Iterator) {
            $bodyWriter = ($protocol >= 1.1)
                ? new ChunkedIteratorBodyWriter($destinationStream, $body)
                : new IteratorBodyWriter($destinationStream, $body);
        } else {
            throw new \DomainException(
                'Invalid BodyWriter init parameters'
            );
        }
        
        return $bodyWriter;
    }
    
}

