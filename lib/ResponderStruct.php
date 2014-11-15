<?php

namespace Aerys;

class ResponderStruct extends Struct {
    public $server;
    public $debug;
    public $socket;
    public $reactor;
    public $writeWatcher;
    public $httpDate;
    public $serverToken;
    public $mustClose;
    public $keepAlive;
    public $defaultContentType;
    public $defaultTextCharset;
    public $request;
    public $response;
}
