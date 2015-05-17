<?php

namespace Aerys;

use Amp\Struct;

class Rfc7230RequestCycle {
    use Struct;
    public $client;
    public $vhost;
    public $isVhostValid;
    public $request;
    public $userspaceRequest;
    public $bodyPromiseStream;
    public $response;
    public $responseFilter;
    public $responseWriter;
    public $badFilterKeys;
}
