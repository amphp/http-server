<?php

namespace Aerys;

use Aerys\Http\RequestParser,
    Aerys\Http\MessageWriter,
    Aerys\Http\TempEntityWriter;

class Client {
    
    private $id;
    private $socket;
    private $ip;
    private $port;
    private $serverIp;
    private $serverPort;
    
    private $parser;
    private $writer;
    
    private $requestCount = 0;
    private $preBodyRequest;
    private $tempEntityWriter;
    private $tempEntityWriterRequestLink;
    
    private $pipeline = [];
    private $responses = [];
    private $isWriting = [];
    
    function __construct($socket, $peerName, $serverName, RequestParser $parser, MessageWriter $writer) {
        $clientPortStartPos = strrpos($peerName, ':');
        $clientIp = substr($peerName, 0, $clientPortStartPos);
        $clientPort = substr($peerName, $clientPortStartPos + 1);
        
        $serverPortStartPos = strrpos($serverName, ':');
        $serverIp = substr($serverName, 0, $serverPortStartPos);
        $serverPort = substr($serverName, $serverPortStartPos + 1);
        
        $this->id = (int) $socket;
        $this->socket = $socket;
        $this->ip = $clientIp;
        $this->port = $clientPort;
        $this->serverIp = $serverIp;
        $this->serverPort = $serverPort;
        $this->parser = $parser;
        $this->writer = $writer;
    }
    
    function getId() {
        return $this->id;
    }
    
    function getSocket() {
        return $this->socket;
    }
    
    function getIp() {
        return $this->ip;
    }
    
    function getPort() {
        return $this->port;
    }
    
    function getServerIp() {
        return $this->serverIp;
    }
    
    function getServerPort() {
        return $this->serverPort;
    }
    
    function hasPreBodyRequest() {
        return (bool) $this->preBodyRequest;
    }
    
    function storePreBodyRequest($requestId, array $asgiEnv, Host $host, $needs100Continue) {
        $this->preBodyRequest = [$requestId, $asgiEnv, $host, $needs100Continue];
        $this->pipeline[$requestId] = $asgiEnv;
        $this->isWriting[$requestId] = FALSE;
        
        // We explicitly do no increment the request count until the full response is received
    }
    
    function shiftPreBodyRequest() {
        if ($preBodyRequest = $this->preBodyRequest) {
            $this->preBodyRequest = NULL;
            return $preBodyRequest;
        } else {
            return NULL;
        }
    }
    
    function addRequestToPipeline($requestId, array $asgiEnv) {
        $this->pipeline[$requestId] = $asgiEnv;
        $this->isWriting[$requestId] = FALSE;
        
        $this->requestCount++;
    }
    
    function getRequestCount() {
        return $this->requestCount;
    }
    
    function hasResponse($requestId) {
        return isset($this->responses[$requestId]);
    }
    
    function getResponse($requestId) {
        return $this->responses[$requestId];
    }
    
    function hasRequest($requestId) {
        return isset($this->pipeline[$requestId]);
    }
    
    function getRequest($requestId) {
        return $this->pipeline[$requestId];
    }
    
    function setTempEntityWriter(TempEntityWriter $tempEntityWriter) {
        $this->tempEntityWriter = $tempEntityWriter;
    }
    
    function writeTempEntityData($data) {
        return $this->tempEntityWriter->write($data);
    }
    
    function setResponse($requestId, $asgiResponse) {
        $this->responses[$requestId] = $asgiResponse;
        
        if ($this->preBodyRequest && $this->preBodyRequest[0] == $requestId) {
            $this->requestCount++;
        }
    }
    
    function enqueueResponsesForWrite() {
        foreach ($this->pipeline as $requestId => $asgiEnv) {
            if ($this->isWriting[$requestId]) {
                continue;
            }
            
            $protocol = $asgiEnv['SERVER_PROTOCOL'];
            
            if (isset($this->responses[$requestId])) {
                $this->writer->enqueue($protocol, $this->responses[$requestId]);
                $this->isWriting[$requestId] = TRUE;
            } else {
                break;
            }
        }
    }
    
    function getPipelineFront() {
        reset($this->pipeline);
        $requestId = key($this->pipeline);
        
        return [$requestId, current($this->pipeline), $this->responses[$requestId]];
    }
    
    function getPipelineRequestIds() {
        return array_keys($this->pipeline);
    }
    
    function isWriting($requestId) {
        return $this->isWriting[$requestId];
    }
    
    function shiftPipelineFront() {
        reset($this->pipeline);
        $requestId = key($this->pipeline);
        
        unset(
            $this->pipeline[$requestId],
            $this->responses[$requestId],
            $this->isWriting[$requestId]
        );
    }
    
    function hasStartedParsingRequest() {
        return $this->parser->hasMessageInProgress();
    }
}
