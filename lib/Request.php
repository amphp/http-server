<?php

namespace Aerys;

use Amp\Struct;

class Request {
    use Struct;
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
    public $query;
    public $time;
    public $debug;
    public $locals;
    public $exporter;
}
