<?php

namespace Aerys\Mods\SendFile;

use Aerys\Server,
    Aerys\Mods\BeforeResponseMod,
    Aerys\Handlers\DocRoot\DocRootHandler;

class ModSendFile implements BeforeResponseMod {
    
    private $server;
    private $docRootHandler;
    private $beforeResponsePriority = 45;
    
    function __construct(Server $server, DocRootHandler $docRootHandler) {
        $this->server = $server;
        $this->docRootHandler = $docRootHandler;
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
        
        $docRootHandlerResponse    = $this->docRootHandler->__invoke($asgiEnv);
        $docRootHandlerHeaders     = array_change_key_case($docRootHandlerResponse[2], CASE_UPPER);
        $docRootHandlerResponse[2] = array_merge($docRootHandlerHeaders, $originalHeaders);
        
        $this->server->setResponse($requestId, $docRootHandlerResponse);
    }
}

