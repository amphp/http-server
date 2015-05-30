<?php

namespace Aerys;

use Amp\Struct;

class InternalRequest {
    use Struct;
    public $cryptoInfo;
    public $isEncrypted;
    public $clientPort;
    public $clientAddr;
    public $serverPort;
    public $serverAddr;
    public $serverName;
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
    public $debug;
}
