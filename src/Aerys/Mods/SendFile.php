<?php

namespace Aerys\Mods;

use Aerys\Server,
    Aerys\Handlers\Filesys;

class SendFile implements BeforeResponseMod {
    
    private $server;
    private $filesys;
    
    function __construct(Server $server, Filesys $filesys) {
        $this->server = $server;
        $this->filesys = $filesys;
    }
    
    function beforeResponse($clientId, $requestId) {
        $headers = $this->server->getResponse($requestId)[2];
        
        if (!empty($headers['X-SENDFILE'])) {
            $asgiEnv = $this->server->getRequest($requestId);
            $filePath = '/' . ltrim($headers['X-SENDFILE'], '/\\');
            $asgiResponse = $this->filesys->__invoke($asgiEnv, $requestId, $filePath);
            $this->server->setResponse($requestId, $asgiResponse);
        }
    }
    
}

