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

    // not sure yet //
    public $writeBuffer;
    public $onWriteDrain;
    public $shouldClose;
    public $isDead;
    public $isExported;
    public $onUpgrade;
    public $requestsRemaining;

    // only for http/1.1 //
    public $requestCycles;
    public $currentRequestCycle;
    
    // new //
    public $isHttp2;
    public $h2Streams = [];
}
