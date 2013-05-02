<?php

namespace Aerys;

use Aerys\Writing\WriterFactory,
    Aerys\Parsing\ResourceReadException,
    Aerys\Parsing\MessageParser;

class Pipeline {
    
    private $socket;
    private $parser;
    private $writerFactory;
    private $requests = [];
    private $responses = [];
    private $writing = [];
    private $preBodyRequest;
    private $requestCount = 0;
    private $ioGranularity = 262144;
    
    function __construct($socket, MessageParser $parser, WriterFactory $wf = NULL) {
        $this->setSocket($socket);
        $this->parser = $parser;
        $this->writerFactory = $wf ?: new WriterFactory;
    }
    
    private function setSocket($socket) {
        $this->socket = $socket;
        
        $name = stream_socket_get_name($socket, FALSE);
        list($this->address, $this->port) = $this->parseName($name);
        
        $peerName = stream_socket_get_name($socket, TRUE);
        list($this->peerAddress, $this->peerPort) = $this->parseName($peerName);
        
        $this->isEncrypted = isset(stream_context_get_options($socket)['ssl']);
    }
    
    private function parseName($name) {
        $portStartPos = strrpos($name, ':');
        $addr = substr($name, 0, $portStartPos);
        $port = substr($name, $portStartPos + 1);
        
        return [$addr, $port];
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
    
    function getPeerAddress() {
        return $this->peerAddress;
    }
    
    function getPeerPort() {
        return $this->peerPort;
    }
    
    function isEncrypted() {
        return $this->isEncrypted;
    }
    
    function setRequest($requestId, $request, $trace) {
        if (!isset($this->requests[$requestId])) {
            $this->requestCount++;
        }
        
        $this->requests[$requestId] = $request;
        $this->traces[$requestId] = $trace;
        $this->writing[$requestId] = FALSE;
    }
    
    function getRequest($requestId) {
        return $this->requests[$requestId];
    }
    
    function hasPreBodyRequest() {
        return (bool) $this->preBodyRequest;
    }
    
    function setPreBodyRequest($requestId, array $asgiEnv, $trace, Host $host, $needs100Continue) {
        $this->preBodyRequest = [$requestId, $asgiEnv, $host, $needs100Continue];
        $this->requests[$requestId] = $asgiEnv;
        $this->traces[$requestId] = $trace;
    }
    
    function shiftPreBodyRequest() {
        $preBodyRequest = $this->preBodyRequest;
        $this->preBodyRequest = NULL;
        
        return $preBodyRequest;
    }
    
    function getTrace($requestId) {
        return $this->traces[$requestId];
    }
    
    function setResponse($requestId, $asgiResponse) {
        $this->responses[$requestId] = $asgiResponse;
    }
    
    function getResponse($requestId) {
        return $this->responses[$requestId];
    }
    
    function hasResponse($requestId) {
        return isset($this->responses[$requestId]);
    }
    
    function getRequestIds() {
        return $this->requests ? array_keys($this->requests) : [];
    }
    
    function getRequestCount() {
        return $this->requestCount;
    }
    
    function hasRequestsAwaitingResponse() {
        return (bool) $this->requests;
    }
    
    function clearRequest($requestId) {
        unset(
            $this->requests[$requestId],
            $this->traces[$requestId],
            $this->responses[$requestId],
            $this->writing[$requestId]
        );
    }
    
    function enqueueResponsesForWrite() {
        foreach ($this->requests as $requestId => $asgiEnv) {
            if ($this->writing[$requestId]) {
                $canWrite = TRUE;
            } elseif (isset($this->responses[$requestId])) {
                list($status, $reason, $headers, $body) = $this->responses[$requestId];
                $protocol = $asgiEnv['SERVER_PROTOCOL'];
                $rawHeaders = $this->generateRawHeaders($protocol, $status, $reason, $headers);
                $responseWriter = $this->writerFactory->make($this->socket, $rawHeaders, $body, $protocol);
                $this->writing[$requestId] = $responseWriter;
            } else {
                break;
            }
        }
        
        reset($this->requests);
    }
    
    private function generateRawHeaders($protocol, $status, $reason, array $headers) {
        $msg = "HTTP/$protocol $status";
        
        if ($reason || $reason === '0') {
            $msg .= " $reason";
        }
        
        $msg .= "\r\n";
        
        foreach ($headers as $header => $value) {
            if ($value === (array) $value) {
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
    
    function write() {
        if ($writer = current($this->writing)) {
            $result = $writer->write() ? key($this->writing) : NULL;
        } else {
            $result = NULL;
        }
        
        return $result;
    }
    
    function canWrite() {
        return (bool) current($this->writing);
    }
    
    function isParseInProgress() {
        return $this->parser->hasInProgressMessage();
    }
    
    function read() {
        $data = @fread($this->socket, $this->ioGranularity);
        
        if ($data || $data === '0' || $this->parser->hasBuffer()) {
            return $this->parser->parse($data);
        } elseif (!is_resource($this->socket) || feof($this->socket)) {
            throw new ResourceReadException;
        }
    }
}



























