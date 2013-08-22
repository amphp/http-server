<?php

namespace Aerys\Responders\ReverseProxy;

class Backend {
    
    public $uri;
    public $socket;
    public $parser;
    public $requestQueue = [];
    public $responseQueue = [];
    public $readWatcher;
    public $writeWatcher;
    public $connectWatcher;
    
}
