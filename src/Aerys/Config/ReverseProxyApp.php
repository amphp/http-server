<?php

namespace Aerys\Config;

use Auryn\Injector;

class ReverseProxyApp implements AppLauncher {
    
    private $handlerClass = 'Aerys\Handlers\ReverseProxy\ReverseProxyHandler';
    private $options = [
        'backends' => NULL,
        'maxPendingRequests' => NULL
    ];
    
    function __construct(array $options) {
        if (empty($options['backends'])) {
            throw new ConfigException(
                'Backend socket URI array required'
            );
        }
        
        foreach ($options as $key => $value) {
            if (isset($value) && array_key_exists($key, $this->options)) {
                $this->options[$key] = $value;
            }
        }
    }
    
    function launchApp(Injector $injector) {
        $opts = $this->options;
        $backends = $opts['backends'];
        unset($opts['backends']);
        
        $handler = $injector->make($this->handlerClass, [
            ':backends' => $backends
        ]);
        
        foreach ($opts as $key => $value) {
            $method = 'set' . ucfirst($key);
            if (isset($value) && is_callable([$handler, $method])) {
                $handler->$method($value);
            }
        }
        
        return $handler;
    }
    
}

