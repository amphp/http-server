<?php

namespace Aerys;

use Aerys\Writing\WriterFactory,
    Aerys\Parsing\MessageParser,
    Aerys\Parsing\TempEntityWriter;

class Pipeline {
    
    private $connection;
    private $parser;
    private $writerFactory;
    
    private $id;
    private $requests = [];
    private $responses = [];
    private $inProgressResponses = [];
    private $preBodyRequest;
    private $requestCount = 0;
    
    function __construct($connection, MessageParser $parser, WriterFactory $writerFactory = NULL) {
        $this->connection = $connection;
        $this->parser = $parser;
        $this->writerFactory = $writerFactory ?: new WriterFactory;
        
        $this->id = (int) $connection->getSocket();
    }
    
    private function generateAddressAndPort($name) {
        $portStartPos = strrpos($name, ':');
        $addr = substr($name, 0, $portStartPos);
        $port = substr($name, $portStartPos + 1);
        
        return [$addr, $port];
    }
    
    function hasRequestsAwaitingResponse() {
        return (bool) $this->requests;
    }
    
    function hasRequest($requestId) {
        return isset($this->requests[$requestId]);
    }
    
    function getRequest($requestId) {
        return $this->requests[$requestId];
    }
    
    function setRequest($requestId, $request) {
        $this->requests[$requestId] = $request;
        $this->inProgressResponses[$requestId] = FALSE;
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
    
    function getFront() {
        reset($this->requests);
        $requestId = key($this->requests);
        
        return [$requestId, $this->requests[$requestId], $this->responses[$requestId]];
    }
    
    function shiftFront() {
        $result = $this->getFront();
        $requestId = $result[0];
        
        unset(
            $this->requests[$requestId],
            $this->responses[$requestId],
            $this->inProgressResponses[$requestId]
        );
        
        return $result;
    }
    
    function parse() {
        return $this->parser->parse();
    }
    
    function hasUnfinishedRead() {
        return $this->parser->hasInProgressMessage();
    }
    
    function getRequestIds() {
        return $this->requests ? array_keys($this->requests) : [];
    }
    
    function getId() {
        return $this->id;
    }
    
    function getSocket() {
        return $this->connection->getSocket();
    }
    
    function getConnection() {
        return $this->connection;
    }
    
    function getAddress() {
        return $this->connection->getAddress();
    }
    
    function getPort() {
        return $this->connection->getPort();
    }
    
    function getPeerAddress() {
        return $this->connection->getPeerAddress();
    }
    
    function getPeerPort() {
        return $this->connection->getPeerPort();
    }
    
    function write() {
        return current($this->inProgressResponses)->write()
            ? count($this->inProgressResponses) - 1
            : -1;
    }
    
    function enqueueResponsesForWrite() {
        $pendingResponses = 0;
        
        foreach ($this->requests as $requestId => $asgiEnv) {
            if ($this->inProgressResponses[$requestId]) {
                $pendingResponses++;
            } elseif (isset($this->responses[$requestId])) {
                list($status, $reason, $headers, $body) = $this->responses[$requestId];
                    
                $protocol = $asgiEnv['SERVER_PROTOCOL'];
                $rawHeaders = $this->generateRawHeaders($protocol, $status, $reason, $headers);
                
                $socket = $this->connection->getSocket();
                $responseWriter = $this->writerFactory->make($socket, $rawHeaders, $body, $protocol);
                
                $this->inProgressResponses[$requestId] = $responseWriter;
                $pendingResponses++;
            } else {
                break;
            }
        }
        
        return $pendingResponses;
    }
    
    private function generateRawHeaders($protocol, $status, $reason, array $headers) {
        $msg = "HTTP/$protocol $status";
        
        if ($reason || $reason === '0') {
            $msg .= " $reason";
        }
        
        $msg .= "\r\n";
        
        foreach ($headers as $header => $value) {
            if (is_array($value)) {
                foreach ($value as $nestedValue) {
                    $msg .= "$header: $nestedValue\r\n";
                }
            } else {
                $msg .= "$header: $value\r\n";
            }
        }
        
        $msg .= "\r\n";
        
        return $msg;
    }
    
    function hasPreBodyRequest() {
        return (bool) $this->preBodyRequest;
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
    
    function isEncrypted() {
        return $this->connection->isEncrypted();
    }
    
}

