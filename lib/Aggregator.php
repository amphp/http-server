<?php

namespace Aerys;

class Aggregator {
    private $responders;
    private $resolver;
    private $notFoundResponse;

    public function __construct(array $responders, Resolver $resolver) {
        if (empty($responders)) {
            throw new \InvalidArgumentException(
                'Non-empty array of callables required'
            );
        }

        $this->resolver = $resolver;

        foreach ($responders as $key => $responder) {
            if (!is_callable($responder)) {
                throw new \InvalidArgumentException(
                    "Callable required at \$responder index {$key}"
                );
            }
        }

        $this->responders = array_values($responders);
        $this->notFoundResponse = (new Response)
            ->setStatus(Status::NOT_FOUND)
            ->setHeader('Content-Type',  'text/html; charset=utf-8')
            ->setBody('<html><body><h1>404 Not Found</h1></body></html>')
        ;
    }

    public function __invoke($request) {
        foreach ($this->responders as $responder) {
            $response = call_user_func($responder, $request);
            if (empty($response)) {
                continue;
            } elseif ($response instanceof Response && $response->getStatus() === Status::NOT_FOUND) {
                continue;
            } elseif ($response instanceof \Generator) {

            } else {
                return $response;
            }

            // @TODO Generator support
            // } elseif ($response instanceof \Generator) {
            //     // ... resolve generator before continuing //
            // }
        }

        return $this->notFoundResponse;
    }
}
