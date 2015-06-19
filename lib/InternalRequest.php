<?php

namespace Aerys;

use Amp\Struct;

class InternalRequest {
    use Struct;

    public $options;
    public $client;
    public $vhost;
    public $preAppResponder;
    public $response;
    public $responseWriter;
    public $exporter;
    public $badFilterKeys = [];
    public $filterErrorFlag;
    public $isServerStopping;
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
    public $time;
    public $httpDate;
    public $locals;
}
