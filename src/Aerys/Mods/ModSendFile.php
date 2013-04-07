<?php

namespace Aerys\Mods;

use Aerys\Server,
    Aerys\Handlers\StaticFiles\Handler;

class ModSendFile implements BeforeResponseMod {
    
    private $server;
    private $filesysHandler;
    private $beforeResponsePriority = 45;
    
    function __construct(Server $server, Handler $filesysHandler) {
        $this->server = $server;
        $this->filesysHandler = $filesysHandler;
    }
    
    function getBeforeResponsePriority() {
        return $this->beforeResponsePriority;
    }
    
    function beforeResponse($requestId) {
        $originalHeaders = $this->server->getResponse($requestId)[2];
        
        if (empty($originalHeaders['X-SENDFILE'])) {
            return;
        }
        
        $filePath = '/' . ltrim($originalHeaders['X-SENDFILE'], '/\\');
        
        // Prevent the X-SENDFILE header from showing up in the final response. We also need to
        // zap pre-existing Content-Length headers so they don't override the new values; all other
        // headers will override the file system handler's ASGI response values.
        unset($originalHeaders['X-SENDFILE'], $originalHeaders['CONTENT-LENGTH']);
        
        $asgiEnv = $this->server->getRequest($requestId);
        
        $filesysHandlerResponse    = $this->filesysHandler->__invoke($asgiEnv);
        $filesysHandlerHeaders     = array_change_key_case($filesysHandlerResponse[2], CASE_UPPER);
        $filesysHandlerResponse[2] = array_merge($filesysHandlerHeaders, $originalHeaders);
        
        $this->server->setResponse($requestId, $filesysHandlerResponse);
    }
}

