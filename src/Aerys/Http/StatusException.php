<?php

namespace Aerys\Http;

class StatusException extends \RuntimeException {
    
    /**
     * Extends the base exception to REQUIRE a message and HTTP status code
     */
    function __construct($msg, $code, \Exception $previous = NULL) {
        parent::__construct($msg, $code, $previous);
    }
    
}

