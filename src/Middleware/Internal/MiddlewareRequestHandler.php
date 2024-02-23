<?php declare(strict_types=1);

namespace Amp\Http\Server\Middleware\Internal;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Driver\HttpDriver;
use Amp\Http\Server\Driver\HttpDriverFactory;
use Amp\Http\Server\Driver\HttpDriverMiddleware;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

/**
 * Wraps a request handler with a single middleware.
 *
 * @see stackMiddleware()
 * @internal
 */
final class MiddlewareRequestHandler implements RequestHandler, HttpDriverMiddleware
{
    public function __construct(
        private readonly Middleware $middleware,
        private readonly RequestHandler $requestHandler,
    ) {
    }

    public function handleRequest(Request $request): Response
    {
        return $this->middleware->handleRequest($request, $this->requestHandler);
    }

    public function createHttpDriver(HttpDriverFactory $factory, RequestHandler $requestHandler, ErrorHandler $errorHandler, Client $client): HttpDriver
    {
        if ($this->requestHandler instanceof HttpDriverMiddleware) {
            return $this->requestHandler->createHttpDriver($factory, $requestHandler, $errorHandler, $client);
        }
        return $factory->createHttpDriver($requestHandler, $errorHandler, $client);
    }
}
