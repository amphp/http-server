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
    public $pipeline = [];
    public $messageCycles = [];
    public $partialMessageCycle;
    public $requestCount;
    public $parser;
    public $readWatcher;
    public $writeWatcher;

}
