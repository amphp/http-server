<?php

namespace Aerys;

use Amp\Struct;

class InternalRequest {
    use Struct;
    public $isEncrypted;
    public $cryptoInfo;
    public $clientPort;
    public $clientAddr;
    public $serverPort;
    public $serverAddr;
    public $trace;
    public $protocol;
    public $method;
    public $headers;
    public $body;
    public $uri;
    public $uriRaw;
    public $uriHost;
    public $uriPort;
    public $uriPath;
    public $uriQuery;
    public $cookies;
    public $remaining;
    public $time;
    public $locals;

    public function generateCookies() {
        $cookies = $this->headers["COOKIE"] ?? [""];
        $this->cookies = array_merge(...array_map('\Aerys\parseCookie', $cookies));
    }
}
