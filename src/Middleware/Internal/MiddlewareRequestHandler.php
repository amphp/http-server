<?php

namespace Amp\Http\Server\Middleware\Internal;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

/**
 * Wraps a request handler with a single middleware.
 *
 * @see stack()
 * @internal
 */
final class MiddlewareRequestHandler implements RequestHandler
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
}
