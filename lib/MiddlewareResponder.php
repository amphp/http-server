<?php

namespace Aerys;

use Amp\Promise;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;

class MiddlewareResponder implements Responder, ServerObserver {
    /** @var \Aerys\Middleware */
    private $middleware;

    /** @var \Aerys\Responder */
    private $next;

    /**
     * @param \Aerys\Responder    $responder
     * @param \Aerys\Middleware[] $middlewares Iteration order determines the order middlewares are applied.
     *
     * @return \Aerys\Responder May return $responder if $middlewares is empty.
     */
    public static function create(Responder $responder, array $middlewares): Responder {
        if (!$middlewares) {
            return $responder;
        }

        $middleware = \end($middlewares);

        while ($middleware) {
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

    /** @inheritdoc */
    public function onStart(Server $server, PsrLogger $logger, ErrorHandler $errorHandler): Promise {
        if ($this->next instanceof ServerObserver) {
            return $this->next->onStart($server, $logger, $errorHandler);
        }

        return new Success;
    }

    /** @inheritdoc */
    public function onStop(Server $server): Promise {
        if ($this->next instanceof ServerObserver) {
            return $this->next->onStop($server);
        }

        return new Success;
    }
}
