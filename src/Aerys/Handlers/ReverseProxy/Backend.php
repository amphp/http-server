<?php

namespace Aerys\Handlers\ReverseProxy;

use Aerys\Server,
    Aerys\Status,
    Aerys\Reason,
    Aerys\Parsing\MessageParser,
    Aerys\Writing\Writer,
    Aerys\Writing\StreamWriter,
    Aerys\Writing\ResourceWriteException;

class Backend {
    
    private $server;
    private $parser;
    private $socket;
    private $uri;
    private $port;
    private $requestQueue = [];
    private $responseQueue = [];
    private $queueSize = 0;
    private $ioGranularity = 262144;
    
    function __construct(Server $server, MessageParser $parser, $socket, $uri) {
        $this->server = $server;
        $this->parser = $parser;
        $this->socket = $socket;
        
        $portStartPos = strrpos($uri, ':');
        $this->port = substr($uri, $portStartPos + 1);
        $this->uri = $uri;
    }
    
    function getUri() {
        return $this->uri;
    }
    
    function getQueueSize() {
        return $this->queueSize;
    }
    
    function enqueueRequest($requestId, array $asgiEnv) {
        $headers = $this->generateRawHeadersFromEnvironment($asgiEnv);
        
        $writer = $asgiEnv['ASGI_INPUT']
            ? new StreamWriter($this->socket, $headers, $asgiEnv['ASGI_INPUT'])
            : new Writer($this->socket, $headers);
        
        $this->requestQueue[$requestId] = $writer;
        $this->queueSize++;
    }
    
    private function generateRawHeadersFromEnvironment(array $asgiEnv) {
        $headerStr = $asgiEnv['REQUEST_METHOD'] . ' ' . $asgiEnv['REQUEST_URI'] . " HTTP/1.1\r\n";
        
        $headerArr = [];
        foreach ($asgiEnv as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $key = str_replace('_', '-', substr($key, 5));
                $headerArr[$key] = $value;
            }
        }
        
        $headerArr['HOST'] = $asgiEnv['SERVER_NAME'] . ':' . $this->port;
        $headerArr['CONNECTION'] = 'keep-alive';
        $headerArr['X-FORWARDED-FOR'] = $asgiEnv['REMOTE_ADDR'];
        
        foreach ($headerArr as $field => $value) {
            if ($value === (array) $value) {
                foreach ($value as $nestedValue) {
                    $headerStr .= "$field: $nestedValue\r\n";
                }
            } else {
                $headerStr .= "$field: $value\r\n";
            }
        }
        
        $headerStr .= "\r\n";
        
        return $headerStr;
    }
    
    /**
     * @throws BackendGoneException
     */
    function write() {
        try {
            return $this->doWrite();
        } catch (ResourceWriteException $e) {
            $this->handleDeadSocket();
        }
    }
    
    private function doWrite() {
        foreach ($this->requestQueue as $requestId => $writer) {
            if ($writer->write()) {
                $this->responseQueue[] = $requestId;
                unset($this->requestQueue[$requestId]);
            } else {
                break;
            }
        }
        
        return $this->requestQueue ? FALSE : TRUE;
    }
    
    private function handleDeadSocket() {
        $unsentRequestIds = $this->requestQueue ? array_keys($this->requestQueue) : [];
        $sentRequestIds = $this->responseQueue;
        $requestIds = array_merge($unsentRequestIds, $sentRequestIds);
        
        if ($requestIds) {
            $asgiResponse = $this->generateBadGatewayResponse();
            
            foreach ($requestIds as $requestId) {
                $this->server->setResponse($requestId, $asgiResponse);
            }
            
            $this->queueSize = 0;
            $this->requestQueue = [];
            $this->responseQueue = [];
        }
        
        throw new BackendGoneException;
    }
    
    private function generateBadGatewayResponse() {
        $status = Status::BAD_GATEWAY;
        $reason = Reason::HTTP_502;
        $body = "<html><body><h1>$status $reason</h1></body></html>";
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Length' => strlen($body)
        ];
        
        return [$status, $reason, $headers, $body];
    }
    
    /**
     * @throws BackendGoneException
     */
    function read() {
        $data = @fread($this->socket, $this->ioGranularity);
        
        if ($data || $data === '0') {
            $this->parse($data);
        } elseif (!is_resource($this->socket) || feof($this->socket)) {
            $this->handleDeadSocket();
        }
    }
    
    private function parse($data) {
        while ($responseArr = $this->parser->parse($data)) {
            $this->assignParsedResponse($responseArr);
            if ($this->parser->hasBuffer()) {
                $data = '';
            } else {
                break;
            }
        }
    }
    
    private function assignParsedResponse(array $responseArr) {
        $requestId = array_shift($this->responseQueue);
        
        unset(
            $responseArr['headers']['CONNECTION'],
            $responseArr['headers']['TRANSFER-ENCODING']
        );
        
        $asgiResponse = [
            $responseArr['status'],
            $responseArr['reason'],
            $responseArr['headers'],
            $responseArr['body']
        ];
        
        $this->server->setResponse($requestId, $asgiResponse);
        $this->queueSize--;
    }
}
