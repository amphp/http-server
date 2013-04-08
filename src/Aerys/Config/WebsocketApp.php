<?php

namespace Aerys\Config;

use Auryn\Injector,
    Aerys\Handlers\Websocket\Endpoint,
    Aerys\Handlers\Websocket\EndpointOptions;

class WebsocketApp implements AppLauncher {
    
    private $endpoints;
    private $handlerClass = "Aerys\\Handlers\\Websocket\\Handler";
    
    function __construct(array $endpoints) {
        $this->endpoints = $endpoints;
    }
    
    function launchApp(Injector $injector) {
        return $injector->make($this->handlerClass, [
            ':endpoints' => $this->endpoints
        ]);
    }
    
}

