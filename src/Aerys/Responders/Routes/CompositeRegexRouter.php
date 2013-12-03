<?php

namespace Aerys\Responders\Routes;

class CompositeRegexRouter implements RouteMatcher {

    private $staticRoutes = [];
    private $variableRoutes = [];
    private $expressionToOffsetMap = [];
    private $currentOffset = 1; // skip offset 0, which is the full match
    private $compositeRegex = '';

    /**
     * Add a callable handler for the specified HTTP method verb and request URI route
     *
     * @param string $httpMethod
     * @param string $route
     * @param callable $handler
     * @throws BadRouteException
     * @return void
     */
    function addRoute($httpMethod, $route, callable $handler) {
        if ($this->containsVariable($route)) {
            $this->addVariableRoute($httpMethod, $route, $handler);
        } else {
            $this->staticRoutes[$route][$httpMethod] = $handler;
        }
    }

    /**
     * Match the specified HTTP method verb and URI against added routes
     *
     * @param string $httpMethod
     * @param string $uri
     * @return array
     */
    function matchRoute($httpMethod, $uri) {
        if (isset($this->staticRoutes[$uri][$httpMethod])) {
            return [self::MATCHED, $this->staticRoutes[$uri][$httpMethod], []];
        } elseif ($this->compositeRegex) {
            return $this->doCompositeRegexMatch($httpMethod, $uri);
        } else {
            return [self::NOT_FOUND];
        }
    }

    private function doCompositeRegexMatch($httpMethod, $uri) {
        if (!preg_match($this->compositeRegex, $uri, $matches)) {
            return [self::NOT_FOUND];
        }

        // find first non-empty match
        for ($i = 1; '' === $matches[$i]; ++$i);

        $rules = $this->variableRoutes[$i];
        if (!isset($rules[$httpMethod])) {
            return [self::METHOD_NOT_ALLOWED, array_keys($rules)];
        }

        $rule = $rules[$httpMethod];
        $vars = [];
        foreach ($rule->variables as $var) {
            $vars[$var] = $matches[$i++];
        }
        return [self::MATCHED, $rule->handler, $vars];
    }

    private function escapeUriForRule($string) {
        $expression = str_replace(
            '\$', '$', preg_quote($string, '~')
        );
        return str_replace('\\$', '\\\\$', $expression);
    }

    private function containsVariable($escapedUri) {
        $varPos = strpos($escapedUri, '$');
        return $varPos !== FALSE && $escapedUri[$varPos-1] !== '\\';
    }

    private function buildExpressionForRule(Rule $rule) {
        return preg_replace_callback('~(?<!\\\\)(\$#?)([^/]*)~', function ($matches) use ($rule) {
            list(, $prefix, $var) = $matches;

            if (strlen($var) < 1) {
                throw new BadRouteException(
                    sprintf(self::E_REQUIRES_IDENTIFIER_STR, $prefix),
                    self::E_REQUIRES_IDENTIFIER_CODE
                );
            }

            if (isset($rule->variables[$var])) {
                throw new BadRouteException(
                    sprintf(self::E_DUPLICATE_PARAMETER_STR, $var),
                    self::E_DUPLICATE_PARAMETER_CODE
                );
            }

            $rule->variables[$var] = $var;

            $def = $prefix === '$#' ? '\d+' : '[^/]+';
            return "($def)";
        }, $this->escapeUriForRule($rule->route));
    }

    private function addVariableRoute($httpMethod, $route, $handler) {
        $rule = new Rule;

        $rule->httpMethod = $httpMethod;
        $rule->route = $route;
        $rule->handler = $handler;
        $rule->expression = $this->buildExpressionForRule($rule);

        $offset =& $this->expressionToOffsetMap[$rule->expression];
        if (null === $offset) {
            $offset = $this->currentOffset;
            $this->currentOffset += count($rule->variables);
        }

        $this->variableRoutes[$offset][$httpMethod] = $rule;
        $this->buildCompositeRegex();
    }

    private function buildCompositeRegex() {
        $expressions = array_keys($this->expressionToOffsetMap);
        $this->compositeRegex = '~^(?:' . implode('|', $expressions) . ')$~';
    }

}
