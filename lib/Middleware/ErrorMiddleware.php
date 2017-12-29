<?php

namespace Aerys\Middleware;

use Aerys\Middleware;
use Aerys\Request;
use Aerys\Responder;
use Amp\Promise;
use function Amp\call;

class ErrorMiddleware implements Middleware {
    private $errorHandler;

    public function __construct(callable $errorHandler) {
        $this->errorHandler = $errorHandler;
    }

    public function process(Request $request, Responder $responder): Promise {
        return call(function () use ($request, $responder) {
            /** @var \Aerys\Response $response */
            $response = yield $responder->respond($request);

            if ($response->getStatus() > 400) {
                return yield call($this->errorHandler, $request, $response);
            }

            return $response;
        });
    }
}
