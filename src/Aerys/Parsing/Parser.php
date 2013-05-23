<?php

namespace Aerys\Parsing;

interface Parser {
    
    const MODE_REQUEST = 1;
    const MODE_RESPONSE = 2;
    
    const AWAITING_HEADERS = 0;
    const BODY_IDENTITY = 1;
    const BODY_IDENTITY_EOF = 2;
    const BODY_CHUNKS = 3;
    const TRAILERS_START = 4;
    const TRAILERS = 5;
    
    function parse($data);
    function getParsedMessageArray();
    function setOptions(array $options);
    function hasUnfinishedMessage();
    function hasBuffer();
    
}
