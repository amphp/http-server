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

    public $writeBuffer = "";
    public $bufferSize = 0;

    /** @var \Amp\Deferred */
    public $bufferDeferred;

    public $onWriteDrain;
    public $shouldClose = false;
    public $isDead = 0;
    public $isExported;
    public $remainingRequests;
    public $pendingResponses = 0;

    /** @var \Aerys\Options */
    public $options;

    /** @var \Aerys\HttpDriver */
    public $httpDriver;
    public $exporter; // Requires Client object as first argument // @TODO cyclic reference to Server object

    /** @var \Amp\Emitter[] */
    public $bodyEmitters = [];

    public $parserEmitLock = false;

    public $allowsPush = true;
    public $window = 65536;
    public $initialWindowSize = 65536;
    public $streamId = 0;
    public $streamEnd = [];
    public $streamWindow = [];
    public $streamWindowBuffer = [];
}
