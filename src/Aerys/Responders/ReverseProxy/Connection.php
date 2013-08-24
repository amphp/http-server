<?php

namespace Aerys\Responders\ReverseProxy;

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
