<?php

namespace Aerys\Mods\Websocket;

use Aerys\Server,
    Aerys\Mods\OnHeadersMod,
    Aerys\Handlers\Websocket\WebsocketHandler;

class ModWebsocket implements OnHeadersMod {
    
    private $server;
    private $websocketHandler;
    
    function __construct(Server $server, WebsocketHandler $websocketHandler) {
        $this->server = $server;
        $this->websocketHandler = $websocketHandler;
    }
    
    function onHeaders($requestId) {
        $asgiEnv = $this->server->getRequest($requestId);
        $asgiResponse = $this->websocketHandler->__invoke($asgiEnv);
        
        // The Websocket handler returns a 404 if the requested resource path
        // isn't registered as an endpoint. If we get this back we don't need
        // to take any action. Otherwise, an endpoint match was found and the
        // result should be assigned as the response to this request.
        if ($asgiResponse[0] != 404) {
            $this->server->setResponse($requestId, $asgiResponse);
        }
    }
}
