<?php

namespace Aerys\Websocket;

use Amp\Struct;

class Rfc6455Client {
    use Struct;

    public $id;
    public $socket;
    public $serverRefClearer;
    public $parser;
    public $builder = [];
    public $readWatcher;
    public $writeWatcher;
    public $msgPromisor;

    public $pingCount = 0;
    public $pongCount = 0;

    public $writeBuffer = '';
    public $writeDeferred;
    public $writeDataQueue = [];
    public $writeDeferredDataQueue = [];
    public $writeControlQueue = [];
    public $writeDeferredControlQueue = [];

    // getInfo() properties
    public $connectedAt;
    public $closedAt = 0;
    public $lastReadAt = 0;
    public $lastSentAt = 0;
    public $lastDataReadAt = 0;
    public $lastDataSentAt = 0;
    public $bytesRead = 0;
    public $bytesSent = 0;
    public $framesRead = 0;
    public $framesSent = 0;
    public $messagesRead = 0;
    public $messagesSent = 0;

    public $capacity;
    public $framesLastSecond = 0;
}