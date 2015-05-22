<?php

use Amp\Struct;

class Rfc6455Client {
    use Struct;

    public $id;
    public $socket;
    public $serverRefClearer;
    public $parser;
    public $readWatcher;
    public $writeWatcher;
    public $closeRcvdPromisor;
    public $closeSentPromisor;

    /*
    // these are all from the old implementation -- we may or may not need them
    public $pendingPings = [];
    public $writeBuffer = '';
    public $writeBufferSize = 0;
    public $writeDataQueue = [];
    public $writeControlQueue = [];
    public $writeOpcode;
    public $writeIsFin;
    */

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