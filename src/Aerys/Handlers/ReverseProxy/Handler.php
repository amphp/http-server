<?php

namespace Aerys\Handlers\ReverseProxy;

use Amp\Reactor,
    Aerys\Server,
    Aerys\Parsing\MesssageParser,
    Aerys\Parsing\PeclMesssageParser,
    Aerys\Writing\Writer,
    Aerys\Writing\StreamWriter;

class Handler {
    
    private $reactor;
    private $httpServer;
    private $workServers = [];
    private $parsers = [];
    private $readSubscriptions = [];
    private $writers = [];
    private $responses = [];
    private $onHeadersPriority = 90;
    private $autoWriteInterval = 0.04;
    
    function __construct(Reactor $reactor, Server $httpServer, WriterFactory $wf = NULL) {
        $this->reactor = $reactor;
        $this->httpServer = $httpServer;
        $this->writerFactory = $wf ?: new WriterFactory;
        //$this->reactor->repeat(function() { $this->write(); }, $this->autoWriteInterval);
    }
    
    private function connectWorkServer($uri) {
        // @TODO Connect to uri
        // @TODO Subscribe to reads so we can parse responses
        
        $readSubscription = $this->reactor->onReadable($workSock, function($workSock) {
            $this->read($workSock);
        });
        
        $workSockId = (int) $workSock;
        $this->workServers[$workSockId] = $workSock;
        $this->parsers[$workSockId] = new ResponseParser;
        $this->readSubscriptions[$workSockId] = $readSubscription;
    }
    
    function __invoke($asgiEnv, $requestId) {
        $trace = $this->httpServer->getTrace($requestId);
        $headers = rtrim($trace, "\r\n") . "\r\n\r\n";
        $writer = $asgiEnv['ASGI_INPUT']
            ? new StreamWriter($workSock, $trace, $asgiEnv['ASGI_INPUT'])
            : new Writer($workSock, $trace);
        
        $this->assignWorkServer($requestId, $writer);
        $this->autoWrite();
    }
    
    private function assignWorkServer($requestId, $writer) {
        // @TODO select work server
        $workSockId = (int) $workSock;
        $this->writers[$workSockId][$requestId] = $writer;
    }
    
    private function autoWrite() {
        foreach ($this->writers as $workSockId => $requestArr) {
            if (!$requestArr) {
               continue;
            }
            
            $writer = current($requestArr);
            
            if ($writer->write()) {
                $requestId = key($requestArr);
                $this->responses[$workSockId][$requestId] = NULL;
                unset($requestArr[$requestId]);
            }
        }
    }
    
    private function read($workSock) {
        $workSockId = (int) $workSock;
        $parser = $this->parsers[$workSockId];
        
        $data = @fread($workSock, 8192);
        
        if ($data || $data === '0' || $parser->canContinue()) {
            $responseArr = $parser->parse($data) ?: NULL;
        } elseif (!is_resource($workSock)) {
            throw new ResourceReadException;
        }
        
        if ($responseArr = $this->read($workSock)) {
            $this->onWorkerResponse($workSock, $responseArr);
        }
    }
    
    private function onWorkerResponse($workSock, $responseArr) {
        
    }
    
}











































