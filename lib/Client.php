<?php

namespace Aerys;

use Amp\Struct;

class Client {
    const CLOSED_RD = 1;
    const CLOSED_WR = 2;
    const CLOSED_RDWR = 3;
    
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
    public $isDead = 0;
    public $isExported;
    public $remainingKeepAlives;
    public $pendingResponses = 0;

    public $options;
    public $httpDriver;
    public $exporter; // Requires Client object as first argument // @TODO cyclic reference to Server object

    public $bodyPromisors = [];

    public $parserEmitLock = false;

    public $allowsPush = true;
    public $window = 65536;
    public $initialWindowSize = 65536;
    public $streamId = 0;
    public $streamEnd = [];
    public $streamWindow = [];
    public $streamWindowBuffer = [];
}
