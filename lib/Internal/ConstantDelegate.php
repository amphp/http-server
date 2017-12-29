<?php

namespace Aerys\Internal;

use Aerys\Delegate;
use Aerys\Request;
use Aerys\Responder;
use Amp\Promise;

class ConstantDelegate implements Delegate {
    private $responder;

    public function __construct(Responder $responder) {
        $this->responder = $responder;
    }

    public function delegate(Request $request): Promise {
        return $this->responder->respond($request);
    }
}
