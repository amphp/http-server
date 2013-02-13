<?php

namespace Aerys\Http\BodyWriters;

use Aerys\Http\ByteRangeBody,
    Aerys\Http\MultiPartByteRangeBody;

class BodyWriterFactory {
    
    function make($destination, $body, $protocol, $contentLength) {
        if ($contentLength && is_string($body)) {
            return new String($destination, $body, $contentLength);
        } elseif ($contentLength && is_resource($body)) {
            return new Resource($destination, $body, $contentLength);
        } elseif ($body instanceof ByteRangeBody) {
            return new ByteRange($destination, $body);
        } elseif ($body instanceof MultiPartByteRangeBody) {
            return new MultiPartByteRanges($destination, $body);
        } elseif ($body instanceof \Iterator) {
            return ($protocol >= 1.1)
                ? new ChunkedStream($destination, $body)
                : new Stream($destination, $body);
        } else {
            throw new \InvalidArgumentException(
                'Invalid MessageWriter body type'
            );
        }
    }
    
}
