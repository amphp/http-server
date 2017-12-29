<?php

namespace Aerys\Internal;

use Aerys\Request;
use Aerys\Responder;
use Amp\Promise;
use function Amp\call;

class CallableResponder implements Responder {
    private $callable;

    public function __construct(callable $callable) {
        $this->callable = $callable;
    }

    public function respond(Request $request): Promise {
        return call($this->callable, $request);
    }
}
