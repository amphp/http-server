<?php

namespace Aerys\Config;

use Auryn\Injector,
    Aerys\Handlers\Websocket\Endpoint,
    Aerys\Handlers\Websocket\EndpointOptions;

class WebsocketLauncher implements Launcher {
    
    private $endpoints;
    private $handlerClass = "Aerys\Handlers\Websocket\WebsocketHandler";
    
    function __construct(array $endpoints) {
        $this->endpoints = $endpoints;
    }
    
    function launchApp(Injector $injector) {
        return $injector->make($this->handlerClass, [
            ':endpoints' => $this->endpoints
        ]);
    }
    
}

