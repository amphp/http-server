<?php

namespace Amp\Http\Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\Responder;
use Amp\Http\Server\Server;
use Amp\Http\Server\ServerObserver;
use Amp\Promise;

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
        $promises = [];

        if ($this->middleware instanceof ServerObserver) {
            $promises[] = $this->middleware->onStart($server);
        }

        if ($this->next instanceof ServerObserver) {
            $promises[] = $this->next->onStart($server);
        }

        return Promise\all($promises);
    }

    /** @inheritdoc */
    public function onStop(Server $server): Promise {
        $promises = [];

        if ($this->middleware instanceof ServerObserver) {
            $promises[] = $this->middleware->onStop($server);
        }

        if ($this->next instanceof ServerObserver) {
            $promises[] = $this->next->onStop($server);
        }

        return Promise\all($promises);
    }
}
