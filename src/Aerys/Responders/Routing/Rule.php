<?php

namespace Aerys\Responders\Routing;

class Rule {
    public $httpMethod;
    public $route = '';
    public $expression = '';
    public $variables = [];
    public $handler;
}

