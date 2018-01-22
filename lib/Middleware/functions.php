<?php

namespace Aerys\Middleware;

use Aerys\Middleware;
use Aerys\Responder;

/**
 * Wraps a responder with the given set of middlewares.
 *
 * @param Responder    $responder Responder to wrap.
 * @param Middleware[] $middlewares Middlewares to apply; order determines the order of application.
 *
 * @return Responder Wrapped responder.
 */
function stack(Responder $responder, Middleware ...$middlewares) {
    if (!$middlewares) {
        return $responder;
    }

    $middleware = \end($middlewares);

    while ($middleware) {
        $responder = new MiddlewareResponder($middleware, $responder);
        $middleware = \prev($middlewares);
    }

    return $responder;
}
