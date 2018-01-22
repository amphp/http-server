<?php

namespace Aerys;

use Amp\Promise;

/**
 * Middlewares allow pre-processing of requests and post-processing of responses.
 *
 * @see stack() for how to apply a middleware to a responder.
 */
interface Middleware {
    /**
     * @param Request   $request
     * @param Responder $responder Request responder.
     *
     * @return Promise<\Aerys\Response>
     */
    public function process(Request $request, Responder $responder): Promise;
}
