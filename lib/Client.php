<?php

namespace Aerys;

/**
 * Client objects aggregate all information relevant to a connected socket
 * client in one place.
 */
class Client extends \Amp\Struct {
    public $id;
    public $socket;
    public $clientAddress;
    public $clientPort;
    public $serverAddress;
    public $serverPort;
    public $isEncrypted;
    public $pipeline = [];
    public $cycles = [];
    public $partialCycle;
    public $requestCount;
    public $requestParser;
    public $readWatcher;
    public $writeWatcher;
    public $pendingResponder;
    
    public $body; // @TODO Kill this as it's only temporary for BC
}
