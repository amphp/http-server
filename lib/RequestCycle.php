<?php

namespace Aerys;

use Amp\Struct;

class RequestCycle {
    use Struct;
    public $client;
    public $vhost;
    public $preAppResponder;
    public $internalRequest;
    public $bodyPromisor;
    public $response;
    public $badCodecKeys = [];
}
