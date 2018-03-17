<?php

namespace Amp\Http\Server\RequestHandler;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Server\ServerObserver;
use Amp\Http\Status;
use Amp\Promise;
use function Amp\call;

/**
 * Calls each given request handler until a non-404 response is returned from a handler, returning that response. If all
 * handlers are exhausted, the final 404 response is returned.
 */
final class RequestHandlerStack implements RequestHandler, ServerObserver {
    /** @var RequestHandler[] */
    private $requestHandlers;

    /**
     * @param RequestHandler[] $requestHandlers
     *
     * @throws \Error If the `$requestHandlers` array is empty or a non-RequestHandler is in the array.
     */
    public function __construct(array $requestHandlers) {
        if (empty($requestHandlers)) {
            throw new \Error("At least one request handler must be defined");
        }

        foreach ($requestHandlers as $requestHandler) {
            if (!$requestHandler instanceof RequestHandler) {
                throw new \TypeError("The array of request handlers must contain only instances of " . RequestHandler::class);
            }
        }

        $this->requestHandlers = $requestHandlers;
    }

    /**
     * Tries each registered request handler until a non-404 response is returned.
     *
     * {@inheritdoc}
     */
    public function handleRequest(Request $request): Promise {
        return call(function () use ($request) {
            foreach ($this->requestHandlers as $requestHandler) {
                $response = yield $requestHandler->handleRequest($request);

                \assert($response instanceof Response, "Request handler must return an instance of " . Response::class);

                if ($response->getStatus() !== Status::NOT_FOUND) {
                    break;
                }
            }

            return $response;
        });
    }

    public function onStart(Server $server): Promise {
        $promises = [];
        foreach ($this->requestHandlers as $requestHandler) {
            if ($requestHandler instanceof ServerObserver) {
                $promises[] = $requestHandler->onStart($server);
            }
        }

        return Promise\all($promises);
    }

    public function onStop(Server $server): Promise {
        $promises = [];
        foreach ($this->requestHandlers as $requestHandler) {
            if ($requestHandler instanceof ServerObserver) {
                $promises[] = $requestHandler->onStop($server);
            }
        }

        return Promise\all($promises);
    }
}
