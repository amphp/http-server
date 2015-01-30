<?php

namespace Aerys;

use Amp\Promise;

class MultiApplication implements ServerObserver {
    private $debug;
    private $applications;
    private $asgiResponderFactory;
    private $notFoundResponse;

    public function __construct(array $applications, AsgiResponderFactory $arf = null) {
        // We reference numeric indexes in the app array so array_values() matters here!
        $this->applications = array_values($applications);
        $this->asgiResponderFactory = $arf ?: new AsgiResponderFactory;
        $this->notFoundResponse = [
            'status' => HTTP_STATUS["NOT_FOUND"],
            'reason' => HTTP_REASON[HTTP_STATUS["NOT_FOUND"]],
            'body'   => '<html><body><h1>404 Not Found</h1></body></html>',
        ];
    }

    public function __invoke(array $request, $nextAppIndex = 0) {
        try {
            if (isset($this->applications[$nextAppIndex])) {
                $requestHandler = $this->applications[$nextAppIndex++];
                $result = $requestHandler($request);
                return $this->makeResponderFromHandlerResult($request, $result, $nextAppIndex);
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
     * @param int $nextAppIndex
     * @return Responder
     */
    public function makeResponderFromHandlerResult(array $request, $result, $nextAppIndex) {
        if ($result instanceof Responder) {
            return $result;
        } elseif ($result instanceof \Generator) {
            return new MultiYieldResponder($this, $nextAppIndex, $request, $result);
        } elseif ($result instanceof Promise) {
            return new MultiPromiseResponder($this, $nextAppIndex, $request, $result);
        } elseif ($result instanceof \ArrayAccess || is_array($result)) {
            return $this->makeResponderFromArray($request, $result, $nextAppIndex);
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

    private function makeResponderFromArray($request, $response, $nextAppIndex) {
        if (empty($this->applications[$nextAppIndex]) ||
            empty($response['status']) ||
            $response['status'] != HTTP_STATUS["NOT_FOUND"]
        ) {
            return $this->asgiResponderFactory->make($response);
        } else {
            return $this->__invoke($request, $nextAppIndex);
        }
    }

    /**
     * Create an internal server error Responder (and adhere to server debug settings)
     *
     * @param \Exception $error
     * @return AsgiResponder
     */
    public function makeErrorResponder(\Exception $error) {
        $msg = $this->debug ? "<pre>{$error}</pre>" : "Something went wrong :(";
        $entity = "<html><body><h1>500 Internal Server Error</h1><hr/><p>{$msg}</p></body></html>";

        return $this->asgiResponderFactory->make([
            'status' => HTTP_STATUS["INTERNAL_SERVER_ERROR"],
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
            $this->debug = $server->getOption(Server::OP_DEBUG);
        }
    }
}
