<?php

namespace Aerys\Handlers\Websocket;

use Auryn\Injector,
    Auryn\InjectionException,
    Aerys\Config\ConfigLauncher,
    Aerys\Config\ConfigException;

class WebsocketLauncher extends ConfigLauncher {
    
    private $handlerClass = 'Aerys\Handlers\Websocket\WebsocketHandler';
    
    function launch(Injector $injector) {
        
        $handler = $injector->make($this->handlerClass);
        $injector->share($handler);
        $endpoints = $this->makeEndpoints($injector, $config);
        $injector->unshare(get_class($handler));
    }
    
    private function makeEndpoints(Injector $injector) {
        try {
            $endpoints = [];
            
            foreach ($this->getConfig() as $requestUri => $endpointArr) {
                if (isset($endpointArr['endpoint']) && is_string($endpointArr['endpoint'])) {
                    $endpointArr['endpoint'] = $injector->make($endpointArr['endpoint']);
                    $endpoints[$requestUri] = $endpointArr;
                }
            }
            
            return $endpoints;
            
        } catch (InjectionException $injectionError) {
            throw new ConfigException(
                'Failed injecting websocket dependencies', 
                $errorCode = 0,
                $injectionError
            );
        }
    }
}

