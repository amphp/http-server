<?php

namespace Aerys\Http\Io;

class BodyWriterFactory {
    
    function make($destination, $body, $protocol, $contentLength) {
        if ($contentLength && is_string($body)) {
            return new BodyWriters\String($destination, $body, $contentLength);
        } elseif ($contentLength && is_resource($body)) {
            return new BodyWriters\Resource($destination, $body, $contentLength);
        } elseif ($body instanceof ByteRangeBody) {
            return new BodyWriters\ByteRange($destination, $body);
        } elseif ($body instanceof MultiPartByteRangeBody) {
            return new BodyWriters\MultiPartByteRanges($destination, $body);
        } elseif ($body instanceof \Iterator) {
            return ($protocol >= 1.1)
                ? new BodyWriters\ChunkedStream($destination, $body)
                : new BodyWriters\Stream($destination, $body);
        } else {
            throw new \InvalidArgumentException(
                'Invalid MessageWriter body type'
            );
        }
    }
    
}
