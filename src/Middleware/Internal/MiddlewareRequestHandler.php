<?php

namespace Amp\Http\Server\Middleware\Internal;

use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\ServerObserver;

/**
 * Wraps a request handler with a single middleware.
 *
 * @see stack()
 * @internal
 */
final class MiddlewareRequestHandler implements RequestHandler, ServerObserver
{
    private Middleware $middleware;

    private RequestHandler $next;

    public function __construct(Middleware $middleware, RequestHandler $requestHandler)
    {
        $this->middleware = $middleware;
        $this->next = $requestHandler;
    }

    public function handleRequest(Request $request): Response
    {
        return $this->middleware->handleRequest($request, $this->next);
    }

    public function onStart(HttpServer $server): void
    {
        if ($this->middleware instanceof ServerObserver) {
            $this->middleware->onStart($server);
        }

        if ($this->next instanceof ServerObserver) {
            $this->next->onStart($server);
        }
    }

    public function onStop(HttpServer $server): void
    {
        if ($this->middleware instanceof ServerObserver) {
            $this->middleware->onStop($server);
        }

        if ($this->next instanceof ServerObserver) {
            $this->next->onStop($server);
        }
    }
}
