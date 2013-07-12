<?php

namespace Aerys\Config;

use Auryn\Injector,
    Aerys\Handlers\Websocket\Endpoint,
    Aerys\Handlers\Websocket\EndpointOptions;

class WebsocketLauncher implements Launcher {
    
    private $endpoints;
    private $handlerClass = 'Aerys\Handlers\Websocket\WebsocketHandler';
    
    function __construct(array $endpoints) {
        $this->endpoints = $endpoints;
    }
    
    function launchApp(Injector $injector) {
        $this->makeEndpoints($injector);
        
        try {
            return $injector->make('Aerys\Handlers\Websocket\WebsocketHandler', [
                ':endpoints' => $this->endpoints
            ]);
        } catch (\InvalidArgumentException $handlerError) {
            throw new ConfigException(
                'Invalid websocket mod configuration', 
                $errorCode = 0,
                $handlerError
            );
        }
        
    }
    
    private function makeEndpoints(Injector $injector) {
        try {
            foreach ($this->endpoints as $requestUri => $endpointArr) {
                if (isset($endpointArr['endpoint']) && is_string($endpointArr['endpoint'])) {
                    $endpointArr['endpoint'] = $injector->make($endpointArr['endpoint']);
                    $this->endpoints[$requestUri] = $endpointArr;
                }
            }
        } catch (InjectionException $injectionError) {
            throw new ConfigException(
                'Failed injecting websocket dependencies', 
                $errorCode = 0,
                $injectionError
            );
        }
    }
}

