<?php

namespace Aerys;

use Amp\Promise;

class MiddlewareResponder implements Responder {
    /** @var \Aerys\Middleware */
    private $middleware;

    /** @var \Aerys\Responder */
    private $next;

    /**
     * @param \Aerys\Responder $responder
     * @param \Aerys\Middleware[] $middlewares Iteration order determines the order middlewares are applied.
     *
     * @return \Aerys\Responder May return $responder if $middlewares is empty.
     *
     * @throws \TypeError If a non-Middleware is found in $middlewares.
     */
    public static function create(Responder $responder, array $middlewares): Responder {
        if (empty($middlewares)) {
            return $responder;
        }

        $middleware = \end($middlewares);

        while ($middleware) {
            if (!$middleware instanceof Middleware) {
                throw new \TypeError("The array of middlewares must contain only instances of " . Middleware::class);
            }

            $responder = new self($middleware, $responder);
            $middleware = \prev($middlewares);
        }

        return $responder;
    }

    private function __construct(Middleware $middleware, Responder $responder) {
        $this->middleware = $middleware;
        $this->next = $responder;
    }

    /** {@inheritdoc} */
    public function respond(Request $request): Promise {
        return $this->middleware->process($request, $this->next);
    }
}
