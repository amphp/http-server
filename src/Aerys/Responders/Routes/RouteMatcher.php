<?php

namespace Aerys\Responders\Routes;

interface RouteMatcher {

    const MATCHED = 0;
    const NOT_FOUND = 1;
    const METHOD_NOT_ALLOWED = 2;

    const E_DUPLICATE_PARAMETER_CODE = 1;
    const E_REQUIRES_IDENTIFIER_CODE = 2;
    const E_DUPLICATE_PARAMETER_STR = 'Route cannot use the same variable "%s" twice in the same rule';
    const E_REQUIRES_IDENTIFIER_STR = '%s must be followed by at least 1 character';

    /**
     * @param string $httpMethod
     * @param string $uri
     * @return array
     */
    function matchRoute($httpMethod, $uri);

    /**
     * @param string $httpMethod
     * @param string $route
     * @param callable $handler
     * @return void
     */
    function addRoute($httpMethod, $route, callable $handler);

}
