<?php

namespace Aerys\Parse;

class ParseException extends \RuntimeException {
    private $parsedMsgArr;

    public function __construct(array $parsedMsgArr, $msg, $errNo, \Exception $previousException = NULL) {
        $this->parsedMsgArr = $parsedMsgArr;
        parent::__construct($msg, $errNo, $previousException);
    }

    public function getParsedMsgArr() {
        return $this->parsedMsgArr;
    }
}
