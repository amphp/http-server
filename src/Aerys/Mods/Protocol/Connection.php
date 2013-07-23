<?php

namespace Aerys\Mods\Protocol;

class Connection {
    
    public $id;
    public $socket;
    public $clientName;
    public $serverName;
    public $bytesSent = 0;
    public $bytesRead = 0;
    public $importedAt;
    public $isEncrypted;
    public $handler;
    public $readSubscription;
    public $writeSubscription;
    public $writeBuffer = [];
    
}
