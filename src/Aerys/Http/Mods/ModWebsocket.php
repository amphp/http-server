<?php

namespace Aerys\Http\Mods;

use Aerys\Http\Status,
    Aerys\Http\HttpServer,
    Aerys\Ws\Websocket;

class ModWebsocket implements OnRequestMod {
    
    private $httpServer;
    private $wsHandler;
    
    function __construct(HttpServer $httpServer, Websocket $wsHandler) {
        $this->httpServer = $httpServer;
        $this->wsHandler = $wsHandler;
    }
    
    function onRequest($requestId) {
        $asgiEnv = $this->httpServer->getRequest($requestId);
        $asgiResponse = $this->wsHandler->__invoke($asgiEnv);
        
        if ($asgiResponse[0] != Status::NOT_FOUND) {
            $this->httpServer->setResponse($requestId, $asgiResponse);
        }
    }
    
}

