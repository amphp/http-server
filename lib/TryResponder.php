<?php

namespace Aerys;

use Amp\Coroutine;
use Amp\Promise;

/**
 * Calls each given responder until a non-404 response is returned from a responder, returning that response. If all
 * responders are exhausted, the final 404 response is returned.
 */
class TryResponder implements Responder {
    /** @var \Aerys\Responder[] */
    private $responders = [];

    /**
     * @param \Aerys\Responder[] $responders
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
        return new Coroutine($this->dispatch($request));
    }

    private function dispatch(Request $request): \Generator {
        foreach ($this->responders as $responder) {
            $response = yield $responder->respond($request);

            if (!$response instanceof Response) {
                throw new \Error("Responders must return an instance of " . Response::class);
            }

            if ($response->getStatus() !== HttpStatus::NOT_FOUND) {
                break;
            }
        }

        return $response;
    }
}
