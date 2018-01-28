<?php

namespace Aerys\Middleware;

use Aerys\ErrorHandler;
use Aerys\Middleware;
use Aerys\Request;
use Aerys\Responder;
use Aerys\Server;
use Aerys\ServerObserver;
use Amp\Promise;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;

/**
 * Wraps a responder with a single middleware.
 *
 * @see stack()
 */
class MiddlewareResponder implements Responder, ServerObserver {
    /** @var Middleware */
    private $middleware;

    /** @var Responder */
    private $next;

    public function __construct(Middleware $middleware, Responder $responder) {
        $this->middleware = $middleware;
        $this->next = $responder;
    }

    /** {@inheritdoc} */
    public function respond(Request $request): Promise {
        return $this->middleware->process($request, $this->next);
    }

    /** @inheritdoc */
    public function onStart(Server $server): Promise {
        if ($this->next instanceof ServerObserver) {
            return $this->next->onStart($server);
        }

        return new Success;
    }

    /** @inheritdoc */
    public function onStop(Server $server): Promise {
        if ($this->next instanceof ServerObserver) {
            return $this->next->onStop($server);
        }

        return new Success;
    }
}
