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
    public $parser;
    public $readWatcher;
    public $writeWatcher;
    public $pendingResponder;
}
