<?php

namespace Aerys\Http;

use Aerys\Http\Io\RequestParser,
    Aerys\Http\Io\MessageWriter,
    Aerys\Http\Io\TempEntityWriter;

class ClientSession extends \Aerys\Pipeline\Session {
    
    private $id;
    private $socket;
    private $interface;
    private $port;
    private $serverInterface;
    private $serverPort;
    
    private $preBodyRequest;
    private $requestCount = 0;
    
    function __construct($socket, $peerName, $serverName, RequestParser $parser, MessageWriter $writer) {
        $this->socket = $socket;
        $this->id = (int) $socket;
        
        $clientPortStartPos = strrpos($peerName, ':');
        $this->interface = substr($peerName, 0, $clientPortStartPos);
        $this->port = substr($peerName, $clientPortStartPos + 1);
        
        $serverPortStartPos = strrpos($serverName, ':');
        $this->serverInterface = substr($serverName, 0, $serverPortStartPos);
        $this->serverPort = substr($serverName, $serverPortStartPos + 1);
        
        parent::__construct($parser, $writer);
    }
    
    function getId() {
        return $this->id;
    }
    
    function getSocket() {
        return $this->socket;
    }
    
    function getInterface() {
        return $this->interface;
    }
    
    function getPort() {
        return $this->port;
    }
    
    function getServerInterface() {
        return $this->serverInterface;
    }
    
    function getServerPort() {
        return $this->serverPort;
    }
    
    function enqueueResponsesForWrite() {
        $pendingResponses = 0;
        
        foreach ($this->requests as $requestId => $asgiEnv) {
            if ($this->isWriting[$requestId]) {
                $pendingResponses++;
                continue;
            }
            
            if (isset($this->responses[$requestId])) {
                $writableResponse = $this->responses[$requestId];
                $writableResponse[] = $asgiEnv['SERVER_PROTOCOL'];
                $this->writer->enqueue($writableResponse);
                $this->isWriting[$requestId] = TRUE;
                $pendingResponses++;
            } else {
                break;
            }
        }
        
        return $pendingResponses;
    }
    
    function addPreBodyRequest($requestId, array $asgiEnv, Host $host, $needs100Continue) {
        $this->preBodyRequest = [$requestId, $asgiEnv, $host, $needs100Continue];
        $this->requests[$requestId] = $asgiEnv;
    }
    
    function shiftPreBodyRequest() {
        if ($preBodyRequest = $this->preBodyRequest) {
            $this->preBodyRequest = NULL;
            return $preBodyRequest;
        } else {
            throw new \LogicException(
                'No pre-body request assigned'
            );
        }
    }
    
    function hasPreBodyRequest() {
        return (bool) $this->preBodyRequest;
    }
    
    function setTempEntityWriter(TempEntityWriter $tempEntityWriter) {
        $this->reader->onBody([$tempEntityWriter, 'write']);
    }
    
    function getRequestCount() {
        return $this->requestCount;
    }
    
    function incrementRequestCount() {
        return ++$this->requestCount;
    }
    
    function getTraceBuffer() {
        return $this->reader->getTraceBuffer();
    }
    
}

