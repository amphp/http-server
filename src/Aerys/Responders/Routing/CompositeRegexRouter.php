<?php
 
namespace Aerys\Responders\Routing;
 
class CompositeRegexRouter implements Router {
 
    private $routes = [];
    private $rules = [];
    private $regexs = [];
    private $compositeRegex = '';
 
    private $variables = [];
    private $variableCounter = 0;
 
    /**
     * Match the specified HTTP method verb and URI against added routes
     * 
     * @param string $httpMethod
     * @param string $uri
     * @return array
     */
    function matchRoute($httpMethod, $uri) {
        if (isset($this->routes[$uri][$httpMethod])) {
            $matchResult = [self::MATCHED, $this->routes[$uri][$httpMethod], []];
        } elseif ($this->compositeRegex) {
            $matchResult = $this->doCompositeRegexMatch($httpMethod, $uri);
        } else {
            $matchResult = [self::NOT_FOUND];
        }
 
        return $matchResult;
    }
 
    private function doCompositeRegexMatch($httpMethod, $uri) {
        if (!preg_match($this->compositeRegex, $uri, $matches, PREG_OFFSET_CAPTURE)) {
            return [self::NOT_FOUND];
        }
 
        unset($matches[0]);
 
        $variables = array_diff_key($matches, array_fill_keys(range(1, count($matches) / 2), 0));
        $offset = 0;
        $buffer = '';
        $arguments = [];
 
        foreach ($variables as $key => $value) {
            if ($value[1] >= 0) {
                $var = $this->variables[$key];
                $buffer .= substr($uri, $offset, $value[1] - $offset);
                $buffer .= "{$var[0]}{$var[1]}";
                $offset = $value[1] + strlen($value[0]);
                $arguments[$var[1]] = $value[0];
            }
        }
 
        $buffer .= substr($uri, $offset);
 
        return isset($this->rules[$buffer][$httpMethod])
            ? [self::MATCHED, $this->rules[$buffer][$httpMethod], $arguments]
            : [self::METHOD_NOT_ALLOWED, array_keys($this->rules[$buffer])];
    }
 
    /**
     * Add a callable handler for the specified HTTP method verb and request URI route
     * 
     * @param string $httpMethod
     * @param string $route
     * @param callable $handler
     * @throws \Exception
     * @return void
     */
    function addRoute($httpMethod, $route, callable $handler) {
        $expression = str_replace(
            ['\$'],
            ['$'],
            preg_quote($route, '~')
        );
        $rule = new Rule;
        $offset = 0;
        while (($varPos = strpos($expression, '$', $offset)) !== FALSE) {
            $offset = $varPos+1;
            $end = strpos($expression, '/', $offset);
            if ($end === FALSE) {
                $var = substr($expression, $offset);
            } else {
                $var = substr($expression, $offset, $end - $offset);
            }
            if (strlen($var) < 1) {
                throw new BadRouteException(
                    sprintf(self::E_REQUIRES_IDENTIFIER_STR, '$'),
                    self::E_REQUIRES_IDENTIFIER_CODE
                );
            }
            if ($var[0] === '#') {
                $var = substr($var, 1);
                if (strlen($var) < 1) {
                    throw new BadRouteException(
                        sprintf(self::E_REQUIRES_IDENTIFIER_STR, '$#'),
                        self::E_REQUIRES_IDENTIFIER_CODE
                    );
                }
                $prefix = '$#';
                $def = '\d+';
            } else {
                $prefix = '$';
                $def = '[^/]+';
            }
            if (isset($rule->variables[$var])) {
                throw new BadRouteException(
                    sprintf(self::E_DUPLICATE_PARAMETER_STR, $var),
                    self::E_DUPLICATE_PARAMETER_CODE
                );
            }
            $key = 'i' . $this->variableCounter++;
            $this->variables[$key] = [$prefix, $var];
            $replace = "(?P<$key>$def)";
            $count = 0;
            $expression = str_replace("$prefix$var", $replace, $expression, $count);
            if ($count > 1) {
                throw new BadRouteException(
                    sprintf(self::E_DUPLICATE_PARAMETER_STR, $var),
                    self::E_DUPLICATE_PARAMETER_CODE
                );
            }
            $offset += strlen($replace) - 1;
            $rule->variables[$var] = $key;
        }
 
        if (empty($rule->variables)) {
            $this->routes[$route][$httpMethod] = $handler;
        } else {
            $rule->route = $route;
            $rule->expression = $expression;
            $rule->handler = $handler;
 
            $this->regexs[] = $rule;
            $this->rules[$route][$httpMethod] = $handler;
            $this->buildCompositeRegex();
        }
    }
 
    private function buildCompositeRegex() {
        $expr = '~';
        reset($this->regexs);
        /**
         * @var Rule $rule
         */
        $rule = current($this->regexs);
        $expr .= "(?:{$rule->expression})";
        next($this->regexs);
        while ($rule = current($this->regexs)) {
            $expr .= "|(?:{$rule->expression})";
            next($this->regexs);
        }
        $expr .= "~";
 
        $this->compositeRegex = $expr;
    }
 
}

