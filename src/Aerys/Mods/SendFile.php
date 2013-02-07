<?php

namespace Aerys\Mods;

use Aerys\Server,
    Aerys\Engine\EventBase,
    Aerys\Handlers\Filesys;

class SendFile implements BeforeResponseMod {
    
    private $server;
    private $filesys;
    
    /**
     * @todo Determine appropriate exception to throw on undefined docroot
     */
    static function createMod(Server $server, EventBase $eventBase, array $config) {
        if (isset($config['docRoot'])) {
            $filesys = new Filesys($config['docRoot']);
        } else {
            throw new \Exception;
        }
        
        if (isset($config['indexes'])) {
            $filesys->setIndexes($config['indexes']);
        }
        if (isset($config['staleAfter'])) {
            $filesys->setStaleAfter($config['staleAfter']);
        }
        if (isset($config['types'])) {
            $filesys->setTypes($config['types']);
        }
        if (isset($config['eTagMode'])) {
            $filesys->setEtagMode($config['eTagMode']);
        }
        
        return new SendFile($server, $filesys);
    }
    
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

