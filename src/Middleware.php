<?php declare(strict_types=1);

namespace Amp\Http\Server;

/**
 * Middlewares allow pre-processing of requests and post-processing of responses.
 *
 * @see stackMiddleware() for how to apply a middleware to a request handler.
 */
interface Middleware
{
    public function handleRequest(Request $request, RequestHandler $requestHandler): Response;
}
