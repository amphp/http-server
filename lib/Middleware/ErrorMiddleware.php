<?php

namespace Aerys\Middleware;

use Aerys\Middleware;
use Aerys\Request;
use function Amp\call;

class ErrorMiddleware implements Middleware {
    private $errorHandler;

    public function __construct(callable $errorHandler) {
        $this->errorHandler = $errorHandler;
    }

    public function process(Request $request, callable $next) {
        /** @var \Aerys\Response $response */
        $response = yield $next($request);

        if ($response->getStatus() > 400) {
            return yield call($this->errorHandler, $request, $response);
        }

        return $response;
    }
}
