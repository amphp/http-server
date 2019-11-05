<?php

namespace Amp\Http\Server\Middleware\Internal;

use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\ServerObserver;
use Amp\Promise;

/**
 * Wraps a request handler with a single middleware.
 *
 * @see stack()
 * @internal
 */
final class MiddlewareRequestHandler implements RequestHandler, ServerObserver
{
    /** @var Middleware */
    private $middleware;

    /** @var RequestHandler */
    private $next;

    public function __construct(Middleware $middleware, RequestHandler $requestHandler)
    {
        $this->middleware = $middleware;
        $this->next = $requestHandler;
    }

    /** {@inheritdoc} */
    public function handleRequest(Request $request): Promise
    {
        return $this->middleware->handleRequest($request, $this->next);
    }

    /** @inheritdoc */
    public function onStart(HttpServer $server): Promise
    {
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
    public function onStop(HttpServer $server): Promise
    {
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
