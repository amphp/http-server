<?php

namespace Aerys\Ws\Io;

class StreamFactory {
    
    function __invoke($data, $payloadType) {
        switch (gettype($data)) {
            case 'string':
                return new String($data, $payloadType);
            case 'resource':
                return new Resource($data, $payloadType);
            case 'object':
                if ($data instanceof \Iterator) {
                    return new Sequence($data, $payloadType);
                }
        }
        
        throw new \InvalidArgumentException(
            'Streams may only be created from strings, resources or Iterator instances'
        );
    }
    
}
