<?php

namespace Aerys\Config;

use Auryn\Injector;

class InjectableClassLauncher implements Launcher {
    
    private $handlerClass;
    
    function __construct($handlerClass) {
        if (class_exists($handlerClass)) {
            $this->handlerClass = $handlerClass;
        } else {
            throw new ConfigException(
                "Injectable handler class does not exist and could not be loaded by any " .
                "currently registered class autoloaders" 
            );
        }
    }
    
    function launchApp(Injector $injector) {
        try {
            return $injector->make($this->handlerClass);
        } catch (InjectionException $e) {
            throw new ConfigException(
                'Dependency injection failed while instantiating handler class: ' . $this->handlerClass,
                NULL,
                $e
            );
        }
    }
    
}

