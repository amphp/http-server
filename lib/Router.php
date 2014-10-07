<?php

namespace Aerys;

use FastRoute\Dispatcher;

class Router {
    private $dispatcher;

    public function __construct(Dispatcher $dispatcher) {
        $this->dispatcher = $dispatcher;
    }

    public function __invoke($request) {
        $httpMethod = $request['REQUEST_METHOD'];
        $uriPath = $request['REQUEST_URI_PATH'];
        $matchArr = $this->dispatcher->dispatch($httpMethod, $uriPath);

        switch ($matchArr[0]) {
            case Dispatcher::FOUND:
                $handler = $matchArr[1];
                $request['URI_ROUTE_ARGS'] = $matchArr[2];
                return $handler($request);
            case Dispatcher::NOT_FOUND:
                return NULL;
            case Dispatcher::METHOD_NOT_ALLOWED:
                $response = new Response;
                $response->setStatus(Status::METHOD_NOT_ALLOWED);
                $response->setHeader('Allow', implode(',', $matchArr[1]));
                $response->setBody('<html><body><h1>405 Method Not Allowed</h1></body></html>');
                return $response;
            default:
                throw new \UnexpectedValueException(
                    'Unexpected match code returned from route dispatcher'
                );
        }
    }
}
