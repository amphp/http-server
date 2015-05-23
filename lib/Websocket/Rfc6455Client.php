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
    public $closeRcvdPromisor;
    public $msgPromisor;

    /*
    //  from the old implementation -- we may or may not need it
    public $pendingPings = [];
    */

    public $writeBuffer = '';
    public $writeDataQueue = [];
    public $writeControlQueue = [];

    // getInfo() properties
    public $connectedAt;
    public $lastReadAt;
    public $lastSendAt;
    public $lastDataReadAt;
    public $lastDataSendAt;
    public $bytesRead;
    public $bytesSent;
    public $framesRead;
    public $framesSent;
    public $messagesRead;
    public $messagesSent;
}