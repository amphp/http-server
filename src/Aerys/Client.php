<?php

namespace Aerys;

class Client {
    
    public $id;
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
    public $closeAfterSend = [];
    public $requestCount;
    
    public $parser;
    public $readWatcher;
    public $writeWatcher;
}

