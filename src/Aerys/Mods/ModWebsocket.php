<?php

namespace Aerys\Mods;

use Aerys\Status,
    Aerys\Server,
    Aerys\Handlers\Websocket\Handler;

class ModWebsocket implements OnRequestMod {
    
    private $server;
    private $wsHandler;
    private $onRequestPriority = 50;
    
    function __construct(Server $server, Handler $wsHandler) {
        $this->server = $server;
        $this->wsHandler = $wsHandler;
    }
    
    function getOnRequestPriority() {
        return $this->onRequestPriority;
    }
    
    function onRequest($requestId) {
        $asgiEnv = $this->server->getRequest($requestId);
        $asgiResponse = $this->wsHandler->__invoke($asgiEnv);
        
        // The Websocket handler returns a 404 if the requested resource path
        // isn't registered as an endpoint. If we get this back we don't need
        // to take any action. Otherwise, an endpoint match was found and the
        // result should be assigned as the response to this request.
        if ($asgiResponse[0] != Status::NOT_FOUND) {
            $this->server->setResponse($requestId, $asgiResponse);
        }
    }
}

