<?php

namespace Aerys\Mods;

use Aerys\Server,
    Aerys\Handlers\Filesys;

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
    
    function beforeResponse(Server $server, $requestId) {
        $originalHeaders = $server->getResponse($requestId)[2];
        
        if (empty($originalHeaders['X-SENDFILE'])) {
            return;
        }
        
        $filePath = '/' . ltrim($originalHeaders['X-SENDFILE'], '/\\');
        
        // prevent the X-SENDFILE from showing up in the final response
        unset($originalHeaders['X-SENDFILE']);
        
        $asgiEnv = $server->getRequest($requestId);
        $filesysResponse = $this->filesys->__invoke($asgiEnv, $requestId, $filePath);
        
        $filesysHeaders = $filesysResponse[2];
        $filesysHeaders = array_combine(array_map('strtoupper', array_keys($filesysHeaders)), $filesysHeaders);
        $filesysResponse[2] = array_merge($filesysHeaders, $originalHeaders);
        
        $server->setResponse($requestId, $filesysResponse);
    }
    
}

