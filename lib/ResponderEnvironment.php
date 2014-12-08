<?php

namespace Aerys;

class ResponderEnvironment extends \Amp\Struct {
    public $reactor;
    public $server;
    public $socket;
    public $writeWatcher;
    public $requestId;
    public $request;
    public $httpDate;
    public $serverToken;
    public $mustClose;
    public $keepAlive;
    public $defaultContentType;
    public $defaultTextCharset;
}
