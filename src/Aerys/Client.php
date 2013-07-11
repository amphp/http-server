<?php

namespace Aerys;

class Client {
    
    public $socket;
    public $clientAddress;
    public $clientPort;
    public $serverAddress;
    public $serverPort;
    public $isEncrypted;
    
    public $preBodyRequest;
    public $requests = [];
    public $requestHeaderTraces = [];
    public $responses = [];
    public $pipeline = [];
    public $requestCount;
    
    public $parser;
    public $readSubscription;
    public $writeSubscription;
}

