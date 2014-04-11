<?php

namespace Aerys\Parse;

class ParserFactory {

    private $isExtHttpEnabled;

    function __construct() {
        $this->isExtHttpEnabled = extension_loaded('http');
    }

    function makeParser() {
        return $this->isExtHttpEnabled
            ? new PeclMessageParser(Parser::MODE_REQUEST)
            : new MessageParser(Parser::MODE_REQUEST);
    }

}
