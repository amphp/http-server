<?php

namespace Aerys\Parsing;

use Aerys\Status;

class ReverseProxyMessageParser extends PeclMessageParser {
    
    protected function parseResponseHeaders($startLineAndHeaders) {
        list(
            $this->protocol,
            $this->responseCode,
            $this->responseReason,
            $this->headers
        ) = unserialize($startLineAndHeaders);
    }
    
}

