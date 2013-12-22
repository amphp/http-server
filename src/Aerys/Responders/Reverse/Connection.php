<?php

namespace Aerys\Responders\Reverse;

class Connection {
    public $id;
    public $uri;
    public $socket;
    public $responseParser;
    public $inProgressRequestWriter;
    public $readWatcher;
    public $writeWatcher;
    public $onCompleteCallback;
}
