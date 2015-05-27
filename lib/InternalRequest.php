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
    public $headerLines;
    public $body;
    public $uri;
    public $uriRaw;
    public $uriHost;
    public $uriPort;
    public $uriPath;
    public $uriQuery;
    public $remaining;
    public $locals;
    public $debug;
}
