<?php

namespace Aerys;

use Amp\Struct;

class Client {
    use Struct;
    public $id;
    public $socket;
    public $clientAddr;
    public $clientPort;
    public $serverAddr;
    public $serverPort;
    public $isEncrypted;
    public $cryptoInfo;
    public $requestParser;
    public $readWatcher;
    public $writeWatcher;

    public $writeBuffer;
    public $onWriteDrain;
    public $shouldClose;
    public $isDead;
    public $isExported;
    public $remainingKeepAlives;

    public $httpDriver;
    public $exporter; // Requires Client object as first argument

    public $bodyPromisors = [];

    public $parserEmitLock;
}
