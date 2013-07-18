<?php

namespace Aerys\Handlers\Websocket;

use Auryn\Injector,
    Auryn\InjectionException,
    Aerys\Config\ConfigLauncher,
    Aerys\Config\ConfigException;

class WebsocketLauncher extends ConfigLauncher {
    
    private $handlerClass = 'Aerys\Handlers\Websocket\WebsocketHandler';
    
    function launch(Injector $injector) {
        $config = $this->getConfig();
        $endpoints = $this->makeEndpoints($injector, $config);
        
        try {
            return $injector->make('Aerys\Handlers\Websocket\WebsocketHandler', [
                ':endpoints' => $endpoints
            ]);
        } catch (\InvalidArgumentException $handlerError) {
            throw new ConfigException(
                'Invalid websocket mod configuration', 
                $errorCode = 0,
                $handlerError
            );
        }
    }
    
    private function makeEndpoints(Injector $injector, array $config) {
        try {
            $endpoints = [];
            
            foreach ($config as $requestUri => $endpointArr) {
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

