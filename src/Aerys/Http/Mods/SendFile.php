<?php

namespace Aerys\Http\Mods;

use Aerys\Http\HttpServer,
    Aerys\Http\Filesys;

class SendFile implements BeforeResponseMod {
    
    private $filesys;
    
    function __construct(Filesys $filesys = NULL) {
        $this->filesys = $filesys ?: new Filesys;
    }
    
    function configure(array $config) {
        if (isset($config['docRoot'])) {
            $this->filesys->setDocRoot($config['docRoot']);
        } else {
            throw new \RuntimeException(
                'mod.sendfile requires a document root specification'
            );
        }
        
        if (isset($config['staleAfter'])) {
            $this->filesys->setStaleAfter($config['staleAfter']);
        }
        if (isset($config['types'])) {
            $this->filesys->setTypes($config['types']);
        }
        if (isset($config['eTagMode'])) {
            $this->filesys->setEtagMode($config['eTagMode']);
        }
    }
    
    function beforeResponse(HttpServer $server, $requestId) {
        $originalHeaders = $server->getResponse($requestId)[2];
        
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
        
        $asgiEnv = $server->getRequest($requestId);
        $asgiEnv['PATH_INFO'] = '';
        $asgiEnv['SCRIPT_NAME'] = $filePath;
        
        $filesysResponse = $this->filesys->__invoke($asgiEnv, $requestId);
        $filesysHeaders = array_change_key_case($filesysResponse[2], CASE_UPPER);
        $filesysResponse[2] = array_merge($filesysHeaders, $originalHeaders);
        
        $server->setResponse($requestId, $filesysResponse);
    }
    
}

