<?php

namespace Aerys\Responders;

class CompositeResponder implements AsgiResponder {

    private $responders;
    private $notFoundResponse;

    /**
     * @param array[\Aerys\Responders\AsgiResponder] $responders
     * @throws \InvalidArgumentException
     */
    function __construct(array $responders) {
        if (empty($responders)) {
            throw new \InvalidArgumentException(
                'Non-empty array of AsgiResponder instances required'
            );
        }

        foreach ($responders as $key => $responder) {
            if (!$responder instanceof AsgiResponder) {
                throw new \InvalidArgumentException(
                    "AsgiResponder instance required at \$responder key {$key}"
                );
            }
        }

        $this->responders = $responders;
        
        $this->notFoundResponse = [
            $status = 404,
            $reason = 'Method Not Allowed',
            $headers = [],
            $body = '<html><body><h1>404 Not Found</h1></body></html>'
        ];
    }

    /**
     * Respond to the specified ASGI request environment
     *
     * Each responder is tried until a non-404 response (or NULL for async response) is returned.
     *
     * @param array $asgiEnv The ASGI request
     * @param int $requestId The unique Aerys request identifier
     * @return mixed Returns ASGI response array or NULL for delayed async response
     */
    function __invoke(array $asgiEnv, $requestId) {
        foreach ($this->responders as $responder) {
            $asgiResponse = $responder->__invoke($asgiEnv, $requestId);
            if (!$asgiResponse || $asgiResponse[0] != 404) {
                return $asgiResponse;
            }
        }

        return $this->notFoundResponse;
    }

}
