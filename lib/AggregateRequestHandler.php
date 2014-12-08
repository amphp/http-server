<?php

namespace Aerys;

use Amp\Promise;

class AggregateRequestHandler implements ServerObserver {
    private $requestHandlers;
    private $asgiResponderFactory;
    private $debug;
    private $notFoundResponse = [
        'status' => Status::NOT_FOUND,
        'reason' => Reason::HTTP_404,
        'body'   => '<html><body><h1>404 Not Found</h1></body></html>',
    ];

    public function __construct(array $requestHandlers, AsgiResponderFactory $arf = null) {
        // We reference numeric index positions in the handler array so array_values() matters!
        $this->requestHandlers = array_values($requestHandlers);
        $this->asgiResponderFactory = $arf ?: new AsgiResponderFactory;
    }

    public function __invoke(array $request, $nextHandlerIndex = 0) {
        try {
            if (isset($this->requestHandlers[$nextHandlerIndex])) {
                $requestHandler = $this->requestHandlers[$nextHandlerIndex++];
                $result = $requestHandler($request);
                return $this->makeResponderFromHandlerResult($request, $result, $nextHandlerIndex);
            } else {
                return $this->asgiResponderFactory->make($this->notFoundResponse);
            }
        } catch (\Exception $error) {
            return $this->makeErrorResponder($error);
        }
    }

    /**
     * Create an aggregate responder from a request handler result
     *
     * @param array $request
     * @param mixed $result
     * @param int $nextHandlerIndex
     * @return Responder
     */
    public function makeResponderFromHandlerResult(array $request, $result, $nextHandlerIndex) {
        if ($result instanceof Responder) {
            return $result;
        } elseif ($result instanceof \Generator) {
            return new AggregateGeneratorResponder($this, $nextHandlerIndex, $request, $result);
        } elseif ($result instanceof Promise) {
            return new AggregatePromiseResponder($this, $nextHandlerIndex, $request, $result);
        } elseif ($result instanceof \ArrayAccess || is_array($result)) {
            return $this->makeResponderFromArray($request, $result, $nextHandlerIndex);
        } elseif (is_string($result)) {
            return $this->asgiResponderFactory->make([
                'body' => $result
            ]);
        } else {
            return $this->makeErrorResponder(new \DomainException(
                'Invalid response type returned: ' . gettype($result)
            ));
        }
    }

    private function makeResponderFromArray($request, $response, $nextHandlerIndex) {
        if (empty($this->requestHandlers[$nextHandlerIndex]) ||
            empty($response['status']) ||
            $response['status'] != Status::NOT_FOUND
        ) {
            return $this->asgiResponderFactory->make($response);
        } else {
            return $this->__invoke($request, $nextHandlerIndex);
        }
    }

    /**
     * Create a 500 error Responder (and adhere to server debug settings)
     *
     * @param \Exception $error
     * @return AsgiMapResponder
     */
    public function makeErrorResponder(\Exception $error) {
        $msg = $this->debug ? "<pre>{$error}</pre>" : "Something went wrong :(";
        $entity = "<html><body><h1>500 Internal Server Error</h1><hr/><p>{$msg}</p></body></html>";

        return $this->asgiResponderFactory->make([
            'status' => 500,
            'body' => $entity
        ]);
    }

    /**
     * Is the server running in DEBUG mode?
     *
     * @return bool
     */
    public function getDebugFlag() {
        return $this->debug;
    }

    /**
     * Listen for server state changes so we can retrieve the debug setting when the server starts
     *
     * @param Server $server
     */
    public function onServerUpdate(Server $server) {
        if ($server->getState() === Server::STARTING) {
            $this->debug = $server->getDebugFlag();
        }
    }
}
