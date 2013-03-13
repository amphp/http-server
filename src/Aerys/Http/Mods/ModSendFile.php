<?php

namespace Aerys\Http\Mods;

use Aerys\Http\HttpServer,
    Aerys\Http\Filesys;

class ModSendFile implements BeforeResponseMod {
    
    private $httpServer;
    private $filesys;
    
    function __construct(HttpServer $httpServer, Filesys $filesys) {
        $this->httpServer = $httpServer;
        $this->filesys = $filesys;
    }
    
    function beforeResponse($requestId) {
        $originalHeaders = $this->httpServer->getResponse($requestId)[2];
        
        if (empty($originalHeaders['X-SENDFILE'])) {
            return;
        }
        
        $filePath = '/' . ltrim($originalHeaders['X-SENDFILE'], '/\\');
        
        // Prevent the X-SENDFILE header from showing up in the final response. We also need to
        // zap pre-existing Content-Length headers so they don't override our new one; all other
        // headers will override the filesystem response's values.
        unset(
            $originalHeaders['X-SENDFILE'],
            $originalHeaders['CONTENT-LENGTH']
        );
        
        $asgiEnv = $this->httpServer->getRequest($requestId);
        $asgiEnv['PATH_INFO'] = '';
        $asgiEnv['SCRIPT_NAME'] = $filePath;
        
        $filesysResponse = $this->filesys->__invoke($asgiEnv, $requestId);
        $filesysHeaders = array_change_key_case($filesysResponse[2], CASE_UPPER);
        $filesysResponse[2] = array_merge($filesysHeaders, $originalHeaders);
        
        $this->httpServer->setResponse($requestId, $filesysResponse);
    }
    
}

