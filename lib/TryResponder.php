<?php

namespace Aerys;

use Amp\Coroutine;
use Amp\Promise;

class TryResponder implements Responder {
    /** @var \Aerys\Responder[] */
    private $responders = [];

    /**
     * Tries each registered responder until on does not return a 404 response.
     *
     * {@inheritdoc}
     */
    public function respond(Request $request): Promise {
        return new Coroutine($this->dispatch($request));
    }

    private function dispatch(Request $request): \Generator {
        if (empty($this->responders)) {
            $status = HTTP_STATUS["NOT_FOUND"];
            return new Response\HtmlResponse(makeGenericBody($status), [], $status);
        }

        foreach ($this->responders as $responder) {
            $response = yield $responder->respond($request);

            if (!$response instanceof Response) {
                throw new \Error("Responders must return an instance of " . Response::class);
            }

            if ($response->getStatus() !== HTTP_STATUS["NOT_FOUND"]) {
                return $response;
            }
        }

        return $response; // Return last response, which may be a 404.
    }

    /**
     * Adds a responder to the list of responders. Responders are tried in the order added.
     *
     * @param \Aerys\Responder $responder
     */
    public function addResponder(Responder $responder) {
        $this->responders[] = $responder;
    }
}
