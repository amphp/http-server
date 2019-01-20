<?php

namespace Amp\Http\Server;

use Amp\Promise;

/**
 * Middlewares allow pre-processing of requests and post-processing of responses.
 *
 * @see stack() for how to apply a middleware to a request handler.
 */
interface Middleware
{
    /**
     * @param Request        $request
     * @param RequestHandler $requestHandler
     *
     * @return Promise<\Amp\Http\Server\Response>
     */
    public function handleRequest(Request $request, RequestHandler $requestHandler): Promise;
}
