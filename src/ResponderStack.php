<?php

namespace Amp\Http\Server;

use Amp\Http\Status;
use Amp\Promise;
use function Amp\call;

/**
 * Calls each given responder until a non-404 response is returned from a responder, returning that response. If all
 * responders are exhausted, the final 404 response is returned.
 */
final class ResponderStack implements Responder, ServerObserver {
    /** @var \Amp\Http\Server\Responder[] */
    private $responders;

    /**
     * @param \Amp\Http\Server\Responder[] $responders
     *
     * @throws \Error If the $responders array is empty or a non-Responder is in the array.
     */
    public function __construct(array $responders) {
        if (empty($responders)) {
            throw new \Error("At least one responder must be defined");
        }

        foreach ($responders as $responder) {
            if (!$responder instanceof Responder) {
                throw new \TypeError("The array of responders must contain only instances of " . Responder::class);
            }
        }

        $this->responders = $responders;
    }

    /**
     * Tries each registered responder until a non-404 response is returned.
     *
     * {@inheritdoc}
     */
    public function respond(Request $request): Promise {
        return call(function () use ($request) {
            foreach ($this->responders as $responder) {
                $response = yield $responder->respond($request);

                \assert($response instanceof Response, "Responders must return an instance of " . Response::class);

                if ($response->getStatus() !== Status::NOT_FOUND) {
                    break;
                }
            }

            return $response;
        });
    }

    public function onStart(Server $server): Promise {
        $promises = [];
        foreach ($this->responders as $responder) {
            if ($responder instanceof ServerObserver) {
                $promises[] = $responder->onStart($server);
            }
        }

        return Promise\all($promises);
    }

    public function onStop(Server $server): Promise {
        $promises = [];
        foreach ($this->responders as $responder) {
            if ($responder instanceof ServerObserver) {
                $promises[] = $responder->onStop($server);
            }
        }

        return Promise\all($promises);
    }
}
