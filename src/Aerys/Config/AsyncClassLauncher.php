<?php

namespace Aerys\Config;

use Auryn\Injector;

class AsyncClassLauncher implements Launcher {
    
    private $options = [
        'handlerClass' => NULL,
        'functions' => NULL,
        'binaryCmd' => PHP_BINARY,
        'workerCmd' => NULL,
        'processes' => 8,
        'callTimeout' => NULL
    ];
    
    function __construct(array $options) {
        $rootDir = dirname(dirname(dirname(__DIR__)));
        $this->options['workerCmd'] = $rootDir . '/vendor/Amp/workers/php/worker.php';
        
        $this->setOptions($options);
    }
    
    private function setOptions($options) {
        $options = array_filter($options);
        
        if (!(isset($options['handlerClass']) && class_exists($options['handlerClass']))) {
            throw new ConfigException(
                'Handler class does not exist and could not be loaded by any ' .
                'currently registered class autoloaders'
            );
        } elseif (!$this->validateDispatcherTypehint($options['handlerClass'])) {
            throw new ConfigException(
                __CLASS__ . ' requires handler classes to typehint a Dispatcher argument in ' .
                'their constructors'
            );
        } elseif (!(isset($options['functions']) && file_exists($options['functions']))) {
            throw new ConfigException(
                'Userland async function file does not exist' 
            );
        } else {
            $this->options = array_merge($this->options, $options);
            $this->normalizeProcessCount();
        }
    }
    
    private function validateDispatcherTypehint($className) {
        $reflClass = new \ReflectionClass($className);
        
        if (!$constructor = $reflClass->getConstructor()) {
            return FALSE;
        }
        
        $ctorParams = $constructor->getParameters();
        $dispatcherHint = 'Amp\Async\Dispatcher';
        
        foreach ($ctorParams as $param) {
            if (($paramClass = $param->getClass()) && $paramClass->name === $dispatcherHint) {
                return TRUE;
            }
        }
        
        return FALSE;
    }
    
    private function normalizeProcessCount() {
        $this->options['processes'] = filter_var($this->options['processes'], FILTER_VALIDATE_INT, ['options' => [
            'default' => 8,
            'min_range' => 1
        ]]);
    }
    
    function launchApp(Injector $injector) {
        $cmd = $this->options['binaryCmd'] .' '. $this->options['workerCmd'] .' '. $this->options['functions'];
        
        $dispatcher = $injector->make('Amp\Async\Dispatcher');
        
        if (isset($this->options['callTimeout'])) {
            $dispatcher->setCallTimeout($this->options['callTimeout']);
        }
        
        $injector->share($dispatcher);
        $dispatcher->start($this->options['processes'], $cmd);
        
        try {
            return $injector->make($this->options['handlerClass']);
        } catch (InjectionException $e) {
            throw new ConfigException(
                'Dependency injection failed while instantiating handler class: ' . $this->handlerClass,
                NULL,
                $e
            );
        }
    }
    
}

