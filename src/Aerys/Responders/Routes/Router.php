<?php

namespace Aerys\Responders\Routes;

use Aerys\Responders\AsgiResponder;

class Router implements AsgiResponder {

    private $router;
    private $notFoundResponse;
    private $partialMethodNotAllowedResponse;
    private $partialInternalErrorResponse;

    function __construct(RouteMatcher $router = NULL) {
        $this->router = $router ?: new CompositeRegexRouter;

        $this->notFoundResponse = [
            $status = 404,
            $reason = 'Method Not Allowed',
            $headers = [],
            $body = '<html><body><h1>404 Not Found</h1></body></html>'
        ];
        $this->partialMethodNotAllowedResponse = [
            $status = 405,
            $reason = 'Method Not Allowed',
            $headers = [],
            $body = '<html><body><h1>405 Method Not Allowed</h1></body></html>'
        ];
        $this->partialInternalErrorResponse = [
            $status = 500,
            $reason = 'Internal Server Error',
            $headers = []
        ];
    }

    /**
     * Respond to the specified ASGI request environment
     *
     * Arguments matched from URI variables will be stored as a key-value array in the ASGI
     * environment's "URI_ROUTE_ARGS" key.
     *
     * @param array $asgiEnv The ASGI request
     * @param int $requestId The unique Aerys request identifier
     * @return mixed Returns ASGI response array or NULL for delayed async response
     */
    function __invoke(array $asgiEnv, $requestId) {
        $matchArr = $this->router->matchRoute($asgiEnv['REQUEST_METHOD'], $asgiEnv['REQUEST_URI_PATH']);
        $matchCode = $matchArr[0];

        switch ($matchCode) {
            case RouteMatcher::MATCHED:
                list($matchCode, $handler, $uriArgs) = $matchArr;
                $asgiEnv['URI_ROUTE_ARGS'] = $uriArgs;
                $asgiResponse = $this->invokeRouteHandler($handler, $asgiEnv, $requestId);
                break;
            case RouteMatcher::NOT_FOUND:
                $asgiResponse = $this->notFoundResponse;
                break;
            case RouteMatcher::METHOD_NOT_ALLOWED:
                $asgiResponse = $this->partialMethodNotAllowedResponse;
                $asgiResponse[2] = ['Allow: ' . implode(',', $matchArr[1])];
                break;
        }

        return $asgiResponse;
    }

    private function invokeRouteHandler($handler, array $asgiEnv, $requestId) {
        try {
            $asgiResponse = $handler($asgiEnv, $requestId);
        } catch (\Exception $e) {
            $asgiResponse = $this->partialInternalErrorResponse;
            $body = "<html><body><h1>500 Internal Server Error</h1><p>" . $e->getMessage() . "</p></body></html>";
            $asgiResponse[] = $body;
        }

        return $asgiResponse;
    }

    /**
     * @param string $httpMethod
     * @param string $route
     * @param callable $handler
     * @return \Aerys\Responders\Routes\Router Returns the current object instance
     */
    function addRoute($httpMethod, $route, callable $handler) {
        $this->router->addRoute($httpMethod, $route, $handler);

        return $this;
    }

}
