<?php

namespace Aerys;

use Amp\Struct;

class Rfc7230RequestCycle {
    use Struct;
    public $client;
    public $vhost;
    public $isVhostValid;
    public $request;
    public $bodyPromisor;
    public $response;
    public $responseFilter;
    public $responseWriter;
    public $badFilterKeys;
    public $parseErrorResponder;
}
