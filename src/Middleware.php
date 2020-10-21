<?php

namespace Amp\Http\Server;

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
     * @return Response
     */
    public function handleRequest(Request $request, RequestHandler $requestHandler): Response;
}
