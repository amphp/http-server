<?php

namespace Aerys\Parse;

class ParserFactory {
    public function makeParser() {
        return new MessageParser(Parser::MODE_REQUEST);
    }
}
