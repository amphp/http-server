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
    public $bufferSize = 0;
    public $bufferPromisor;
    public $onWriteDrain;
    public $shouldClose;
    public $isDead;
    public $isExported;
    public $remainingKeepAlives;

    public $options;
    public $httpDriver;
    public $exporter; // Requires Client object as first argument

    public $bodyPromisors = [];

    public $parserEmitLock;

    public $allowsPush = true;
    public $window = 65536;
    public $initialWindowSize = 65536;
    public $streamId = 0;
    public $streamEnd = [];
    public $streamWindow = [];
    public $streamWindowBuffer = [];
}
