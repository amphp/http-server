<?php

namespace Aerys\Mods;

use Aerys\Server,
    Aerys\Handlers\StaticFiles\Handler;

class ModSendFile implements BeforeResponseMod {
    
    private $server;
    private $filesysHandler;
    
    function __construct(Server $server, Handler $filesysHandler) {
        $this->server = $server;
        $this->filesysHandler = $filesysHandler;
    }
    
    function beforeResponse($requestId) {
        $originalHeaders = $this->server->getResponse($requestId)[2];
        
        if (empty($originalHeaders['X-SENDFILE'])) {
            return;
        }
        
        $filePath = '/' . ltrim($originalHeaders['X-SENDFILE'], '/\\');
        
        // Prevent the X-SENDFILE header from showing up in the final response. We also need to
        // zap pre-existing Content-Length headers so they don't override our new one; all other
        // headers will override the filesysHandlertem response's values.
        unset(
            $originalHeaders['X-SENDFILE'],
            $originalHeaders['CONTENT-LENGTH']
        );
        
        $asgiEnv = $this->server->getRequest($requestId);
        $asgiEnv['PATH_INFO'] = '';
        $asgiEnv['SCRIPT_NAME'] = $filePath;
        
        $filesysHandlerResponse    = $this->filesysHandler->__invoke($asgiEnv, $requestId);
        $filesysHandlerHeaders     = array_change_key_case($filesysHandlerResponse[2], CASE_UPPER);
        $filesysHandlerResponse[2] = array_merge($filesysHandlerHeaders, $originalHeaders);
        
        $this->server->setResponse($requestId, $filesysHandlerResponse);
    }
    
}

