<?php

namespace Aerys;

use Aerys\Writing\MessageWriter,
    Aerys\Parsing\MessageParser,
    Aerys\Parsing\TempEntityWriter;

/**
 * @TODO Cleanup this mess
 */
class Pipeline {
    
    private $id;
    private $socket;
    private $address;
    private $port;
    private $serverAddress;
    private $serverPort;
    
    private $parser;
    private $writer;
    private $requests = [];
    private $responses = [];
    private $isWriting = [];
    
    private $preBodyRequest;
    private $requestCount = 0;
    
    function __construct($socket, $peerName, $serverName, MessageParser $parser, MessageWriter $writer) {
        $this->socket = $socket;
        $this->id = (int) $socket;
        
        $clientPortStartPos = strrpos($peerName, ':');
        $this->address = substr($peerName, 0, $clientPortStartPos);
        $this->port = substr($peerName, $clientPortStartPos + 1);
        
        $serverPortStartPos = strrpos($serverName, ':');
        $this->serverAddress = substr($serverName, 0, $serverPortStartPos);
        $this->serverPort = substr($serverName, $serverPortStartPos + 1);
        
        $this->parser = $parser;
        $this->writer = $writer;
    }
    
    function isEmpty() {
        return !$this->requests;
    }
    
    function canWrite() {
        return (bool) $this->responses;
    }
    
    function hasRequest($requestId) {
        return isset($this->requests[$requestId]);
    }
    
    function getRequest($requestId) {
        return $this->requests[$requestId];
    }
    
    function setRequest($requestId, $request) {
        $this->requests[$requestId] = $request;
        $this->isWriting[$requestId] = FALSE;
    }
    
    function hasResponse($requestId) {
        return isset($this->responses[$requestId]);
    }
    
    function getResponse($requestId) {
        return $this->responses[$requestId];
    }
    
    function setResponse($requestId, $asgiResponse) {
        $this->responses[$requestId] = $asgiResponse;
    }
    
    function write() {
        return $this->writer->write();
    }
    
    function front() {
        reset($this->requests);
        $requestId = key($this->requests);
        
        return [$requestId, $this->requests[$requestId], $this->responses[$requestId]];
    }
    
    function shift() {
        reset($this->requests);
        $requestId = key($this->requests);
        
        unset(
            $this->requests[$requestId],
            $this->responses[$requestId],
            $this->isWriting[$requestId]
        );
        
        return $requestId;
    }
    
    function parse() {
        return $this->parser->parse();
    }
    
    function hasUnfinishedRead() {
        return $this->parser->inProgress();
    }
    
    function getRequestIds() {
        return $this->requests ? array_keys($this->requests) : [];
    }
    
    function getId() {
        return $this->id;
    }
    
    function getSocket() {
        return $this->socket;
    }
    
    function getAddress() {
        return $this->address;
    }
    
    function getPort() {
        return $this->port;
    }
    
    function getServerAddress() {
        return $this->serverAddress;
    }
    
    function getServerPort() {
        return $this->serverPort;
    }
    
    /**
     * Enqueue pipelined responses with the writer (maintaining the original request order)
     * 
     * @return Returns the number of queued responses for which writing has yet to complete
     */
    function enqueueResponsesForWrite() {
        $pendingResponses = 0;
        
        foreach ($this->requests as $requestId => $asgiEnv) {
            if ($this->isWriting[$requestId]) {
                $pendingResponses++;
                continue;
            }
            
            if (isset($this->responses[$requestId])) {
                
                $asgiResponse = $this->responses[$requestId];
                $responseHeaders = $asgiResponse[2];
                $contentLength = empty($responseHeaders['CONTENT-LENGTH'])
                    ? NULL
                    : $responseHeaders['CONTENT-LENGTH'];
                
                $protocol = $asgiEnv['SERVER_PROTOCOL'];
                
                $this->writer->enqueue($asgiResponse, $protocol, $contentLength);
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
        $this->parser->setOnBodyCallback([$tempEntityWriter, 'write']);
    }
    
    function getRequestCount() {
        return $this->requestCount;
    }
    
    function incrementRequestCount() {
        return ++$this->requestCount;
    }
    
    function setParseOptions(array $options) {
        $this->parser->setAllOptions($options);
    }
    
}

