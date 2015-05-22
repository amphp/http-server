<?php

namespace Aerys;

use Amp\Struct;

class Rfc7230Client {
    use Struct;
    public $id;
    public $socket;
    public $clientAddr;
    public $clientPort;
    public $serverAddr;
    public $serverPort;
    public $isEncrypted;
    public $requestCycleQueue;
    public $requestCycleQueueSize;
    public $currentRequestCycle;
    public $requestsRemaining;
    public $requestParser;
    public $readWatcher;
    public $writeWatcher;
    public $writeFilter;
    public $writeBuffer;
    public $onWriteDrain;
    public $shouldClose;
    public $isDead;
    public $isExported;
    public $onUpgrade;
}
