<?php

namespace Aerys\Parsing;

class ParseException extends \RuntimeException {
    
    private $parsedMsgArr;
    
    /**
     * Adds an array of parsed message values to the standard exception
     */
    function __construct(array $parsedMsgArr, $msg, $errNo, \Exception $previousException = NULL) {
        $this->parsedMsgArr = $parsedMsgArr;
        parent::__construct($msg, $errNo, $previousException);
    }
    
    /**
     * Retrieve message values parsed prior to the error
     * 
     * @return array Message values parsed prior to the error
     */
    function getParsedMsgArr() {
        return $this->parsedMsgArr;
    }
    
}

