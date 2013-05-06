<?php

use Aerys\Server,
    Aerys\Mods\BeforeResponseMod;

class ExampleBeforeResponseMod implements BeforeResponseMod {
    
    private $server;
    private $beforeResponsePriority = 50;
    
    function __construct(Server $server) {
        $this->server = $server;
    }
    
    function beforeResponse($requestId) {
        $asgiResponse = $this->server->getResponse($requestId);
        list($status, $reason, $headers, $body) = $asgiResponse;
        
        // Replace the body for any 200 response
        if ($status == 200) {
            $newBody = '<html><body><h1>ZANZIBAR!</h1></body><html>';
            
            // Remove any pre-existing Content-Length headers. We could specify our own but we don't
            // have to because the server will automatically add missing Content-Length or
            // Transfer-Encoding headers as needed.
            unset($headers['CONTENT-LENGTH']);
            
            $newAsgiResponse = [$status, $reason, $headers, $newBody];
            
            $this->server->setResponse($requestId, $newAsgiResponse);
        }
    }
    
    function getBeforeResponsePriority() {
        return $this->beforeResponsePriority;
    }
    
}

