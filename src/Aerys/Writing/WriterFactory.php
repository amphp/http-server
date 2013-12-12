<?php

namespace Aerys\Writing;

class WriterFactory {

    function make($destination, $headers, $body) {
        if (!$body || is_string($body)) {
            $writer = new Writer($destination, $headers . $body);
        } elseif (is_resource($body)) {
            $writer = new StreamWriter($destination, $headers, $body);
        } elseif ($body instanceof ByteRangeBody) {
            $writer = new ByteRangeWriter($destination, $headers, $body);
        } elseif ($body instanceof MultiPartByteRangeBody) {
            $writer = new MultiPartByteRangeWriter($destination, $headers, $body);
        } else {
            throw new \DomainException(
                'Invalid BodyWriter init parameters'
            );
        }

        return $writer;
    }

}
