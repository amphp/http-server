<?php

namespace Aerys\Responders;

use Aerys\Status,
    Aerys\Reason,
    Aerys\Response;

class CompositeResponder {

    private $responders;
    private $notFoundResponse;

    /**
     * @param array $responders
     * @throws \InvalidArgumentException
     */
    function __construct(array $responders) {
        if (empty($responders)) {
            throw new \InvalidArgumentException(
                'Non-empty array of callables required'
            );
        }

        foreach ($responders as $key => $responder) {
            if (!is_callable($responder)) {
                throw new \InvalidArgumentException(
                    "Callable required at \$responder index {$key}"
                );
            }
        }

        $this->responders = $responders;
        $this->notFoundResponse = new Response([
            'status' => Status::NOT_FOUND,
            'reason' => Reason::HTTP_400,
            'headers' => ['Content-Type: text/html; charset=utf-8'],
            'body' => '<html><body><h1>404 Not Found</h1></body></html>'
        ]);
    }

    /**
     * Respond to the specified ASGI request environment
     *
     * Each responder is tried until a non-404 response (or NULL for async response) is returned.
     *
     * @param \Aerys\Request $request
     * @return mixed(\Aerys\Response|\Generator)
     */
    function __invoke($request) {
        foreach ($this->responders as $responder) {
            if (!$response = $responder->__invoke($request)) {
                continue;
            } elseif ($response instanceof \Generator) {
                return $response;
            } elseif (empty($response['status']) || $response['status'] != Status::NOT_FOUND) {
                return $response;
            }
        }

        return $this->notFoundResponse;
    }

}
