<?php

namespace Aerys\Responders\Reverse;

class Connection {
    public $id;
    public $uri;
    public $socket;
    public $responseParser;
    public $inProgressRequestId;
    public $inProgressRequestWriter;
    public $readWatcher;
    public $writeWatcher;
}
