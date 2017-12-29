<?php

namespace Aerys\Internal;

use Aerys\Middleware;
use Aerys\Request;
use Aerys\Responder;
use Amp\Promise;

class MiddlewareResponder implements Responder {
    /** @var \Aerys\Middleware */
    private $middleware;

    /** @var \Aerys\Responder */
    private $next;

    public function __construct(Middleware $middleware, Responder $responder) {
        $this->middleware = $middleware;
        $this->next = $responder;
    }

    public function respond(Request $request): Promise {
        return $this->middleware->process($request, $this->next);
    }
}
