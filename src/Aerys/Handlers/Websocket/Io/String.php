<?php

namespace Aerys\Handlers\Websocket\Io;

class String extends Resource {
    
    function __construct($string, $payloadType) {
        if (is_string($string) || (is_object($string) && method_exists($string, '__toString'))) {
            $uri = 'data://text/plain;base64,' . base64_encode($string);
            $resource = fopen($uri, 'r');
            parent::__construct($resource, $payloadType);
        } else {
            throw new \InvalidArgumentException(
                'String::__construct requires a string at Argument 1'
            );
        }
    }
    
}
