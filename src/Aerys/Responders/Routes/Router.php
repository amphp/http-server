<?php

namespace Aerys\Responders\Routes;

class Router {

    private $routeMatcher;

    function __construct(RouteMatcher $router = NULL) {
        $this->routeMatcher = $router ?: new CompositeRegexRouter;
    }

    /**
     * Respond to the specified ASGI request environment
     *
     * Arguments matched from URI variables will be stored as a key-value array in the ASGI
     * environment's "URI_ROUTE_ARGS" key.
     *
     * @param \Aerys\Request $request The ASGI request environment map
     * @param int $requestId The unique Aerys request identifier
     * @return mixed Returns ASGI response array or NULL for delayed async response
     */
    function __invoke($request) {
        $matchArr = $this->routeMatcher->matchRoute($request['REQUEST_METHOD'], $request['REQUEST_URI_PATH']);
        $matchCode = array_shift($matchArr);

        switch ($matchCode) {
            case RouteMatcher::MATCHED:
                list($handler, $uriArgs) = $matchArr;
                $request['URI_ROUTE_ARGS'] = $uriArgs;
                $response = $handler($request);
                break;
            case RouteMatcher::NOT_FOUND:
                $response = NULL;
                break;
            case RouteMatcher::METHOD_NOT_ALLOWED:
                $response = new Response([
                    'status' => Status::METHOD_NOT_ALLOWED,
                    'reason' => Reason::HTTP_405,
                    'headers' => ['Allow: ' . implode(',', $matchArr[1])],
                    'body' => '<html><body><h1>405 Method Not Allowed</h1></body></html>'
                ]);
                break;
        }

        return $response;
    }

    /**
     * @param string $httpMethod
     * @param string $route
     * @param callable $handler
     * @return \Aerys\Responders\Routes\Router Returns the current object instance
     */
    function addRoute($httpMethod, $route, callable $handler) {
        $this->routeMatcher->addRoute($httpMethod, $route, $handler);

        return $this;
    }

}
