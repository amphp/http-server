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

    public $requestCount;
    public $preBodyRequest;
    public $requests = [];
    public $pipeline = [];
    public $closeAfterSend = [];

    public $parser;
    public $readWatcher;
    public $writeWatcher;

}
