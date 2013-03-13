<?php

namespace Aerys\Http\Config;

use Auryn\Injector;

class CallableClassApp implements AppLauncher {
    
    private $handlerClass;
    private $injectionParams;
    
    function __construct($handlerClass, array $injectionParams = []) {
        $this->validateHandlerClass($handlerClass);
        $this->handlerClass = $handlerClass;
        $this->injectionParams = $injectionParams;
    }
    
    private function validateHandlerClass($handlerClass) {
        if (!class_exists($handlerClass)) {
            throw new ConfigException(
                __CLASS__ . "::__construct requires a loadable class name at " .
                "Argument 1; $handlerClass does not exist and could not be loaded by any " .
                "currently registered class autoloaders" 
            );
        }
    }
    
    function launchApp(Injector $injector) {
        return $injector->make($this->handlerClass, $this->injectionParams);
    }
    
}

