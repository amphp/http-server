<?php

namespace Aerys\Mods;

use Aerys\Status,
    Aerys\Server,
    Aerys\Handlers\Websocket\Handler;

class ModWebsocket implements OnRequestMod {
    
    private $server;
    private $wsHandler;
    
    function __construct(Server $server, Handler $wsHandler) {
        $this->server = $server;
        $this->wsHandler = $wsHandler;
    }
    
    function onRequest($requestId) {
        $asgiEnv = $this->server->getRequest($requestId);
        $asgiResponse = $this->wsHandler->__invoke($asgiEnv);
        
        if ($asgiResponse[0] != Status::NOT_FOUND) {
            $this->server->setResponse($requestId, $asgiResponse);
        }
    }
    
}

