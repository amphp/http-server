<?php

namespace Aerys\Responders\Websocket;

class UnknownSocketException extends EndpointException {

    private $badSocketIds;

    function __construct(array $badSocketIds, $msg = '', $code = 0, $previousException = NULL) {
        $this->badSocketIds = $badSocketIds;
        parent::__construct($msg, $code, $previousException);
    }

    function getUnknownSocketIds() {
        return $this->badSocketIds;
    }
}
