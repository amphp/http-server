<?php

namespace Aerys\Config;

use Auryn\Injector,
    Aerys\Handlers\Websocket\Endpoint,
    Aerys\Handlers\Websocket\EndpointOptions;

class WebsocketApp implements AppLauncher {
    
    private $endpoints;
    private $handlerClass = "Aerys\\Handlers\\Websocket\\Handler";
    
    function __construct(array $endpoints) {
        $this->validateEndpoints($endpoints);
        $this->endpoints = $endpoints;
    }
    
    private function validateEndpoints(array $endpoints) {
        if (empty($endpoints)) {
            throw new ConfigException(
                __CLASS__ . "::__construct requires an array mapping URI path keys to Endpoint " .
                "instances at Argument 1"
            );
        }
        
        foreach ($endpoints as $uri => $endpoint) {
            if (!$endpoint instanceof Endpoint) {
                throw new ConfigException(
                    "Invalid websocket Endpoint instance passed to WebsocketApp::__construct at " .
                    "key $uri"
                );
            }
            
            $opts = $endpoint->getOptions();
            
            if (!$opts instanceof EndpointOptions) {
                throw new ConfigException(
                    "Invalid websocket Endpoint instance passed to WebsocketApp::__construct at " .
                    "key $uri; Endpoint::getOptions() must return an EndpointOptions instance"
                );
            }
        }
    }
    
    function launchApp(Injector $injector) {
        return $injector->make($this->handlerClass, [
            ':endpoints' => $this->endpoints
        ]);
    }
    
}

