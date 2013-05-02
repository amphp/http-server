<?php

namespace Aerys\Handlers\ReverseProxy;

use Amp\Reactor,
    Aerys\Server,
    Aerys\Status,
    Aerys\Reason,
    Aerys\Parsing\MessageParser,
    Aerys\Parsing\PeclMessageParser,
    Aerys\Writing\Writer,
    Aerys\Writing\StreamWriter;

class ReverseProxyHandler {
    
    private $reactor;
    private $server;
    private $backends = [];
    private $backendParsers = [];
    private $backendSubscriptions = [];
    private $pendingRequestCounts = [];
    private $requestQueue = [];
    private $responseQueue = [];
    
    private $maxPendingRequests = 1500;
    private $ioGranularity = 262144;
    private $autoWriteInterval = 0.05;
    private $reconnectInterval = 5;
    
    function __construct(Reactor $reactor, Server $server, array $backends) {
        $this->reactor = $reactor;
        $this->server = $server;
        
        foreach ($backends as $backendUri) {
            $this->connectBackend($backendUri);
        }
        
        $reactor->repeat(function() { $this->autoWrite(); }, $this->autoWriteInterval);
        $this->canUsePeclParser = extension_loaded('http');
    }
    
    private function connectBackend($uri) {
        if ($socket = @stream_socket_client($uri, $errNo, $errStr)) {
            stream_set_blocking($socket, FALSE);
            
            $sockId = (int) $socket;
            $this->backends[$sockId] = $socket;
            $this->pendingRequestCounts[$sockId] = 0;
            $this->requestQueue[$backendId] = [];
            
            $portStartPos = strrpos($uri, ':');
            $port = substr($uri, $portStartPos + 1);
            $this->backendPortMap[$sockId] = $port;
            
            $parser = $this->canUsePeclParser
                ? new PeclMessageParser(MessageParser::MODE_RESPONSE)
                : new MessageParser(MessageParser::MODE_RESPONSE);
            
            $this->backendParsers[$sockId] = $parser;
            $this->backendSubscriptions[$sockId] = $this->reactor->onReadable($socket, function($socket) {
                $this->read($socket);
            });
            
        } else {
            $msg = "Socket connect failure: $uri";
            $msg .= $errNo ? "; [Error# $errNo] $errStr" : '';
            
            throw new \RuntimeException($msg);
        }
    }
    
    function __invoke($asgiEnv, $requestId) {
        asort($this->pendingRequestCounts);
        $backendId = key($this->pendingRequestCounts);
        
        if ($this->pendingRequestCounts[$backendId] >= $this->maxPendingRequests) {
            return $this->generateServiceUnavailableResponse($asgiEnv['REQUEST_URI']);
        }
        
        $this->pendingRequestCounts[$backendId]++;
        
        $backendSock = $this->backends[$backendId];
        $backendPort = $this->backendPortMap[$backendId];
        
        $headers = $this->generateRawHeadersFromEnvironment($asgiEnv, $backendPort);
        
        $writer = $asgiEnv['ASGI_INPUT']
            ? new StreamWriter($backendSock, $headers, $asgiEnv['ASGI_INPUT'])
            : new Writer($backendSock, $headers);
        
        $this->requestQueue[$backendId][$requestId] = $writer;
        $this->autoWrite();
    }
    
    private function generateServiceUnavailableResponse($requestUri) {
        $status = Status::SERVICE_UNAVAILABLE;
        $reason = Reason::HTTP_503;
        $body = "<html><body><h1>$status $reason</h1><hr /><p>$requestUri</p></body></html>";
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Length' => strlen($body)
        ];
        
        return [$status, $reason, $headers, $body];
    }
    
    private function generateRawHeadersFromEnvironment(array $asgiEnv, $backendPort) {
        $headerStr = $asgiEnv['REQUEST_METHOD'] . ' ' . $asgiEnv['REQUEST_URI'] . " HTTP/1.1\r\n";
        
        $headerArr = [];
        foreach ($asgiEnv as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $key = str_replace('_', '-', substr($key, 5));
                $headerArr[$key] = $value;
            }
        }
        
        $headerArr['HOST'] = $asgiEnv['SERVER_NAME'] . ':' . $backendPort;
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
    
    private function autoWrite() {
        foreach ($this->requestQueue as $backendId => $writerArr) {
            foreach ($writerArr as $requestId => $writer) {
                if ($writer->write()) {
                    $this->responseQueue[$backendId][] = $requestId;
                    unset($this->requestQueue[$backendId][$requestId]);
                } else {
                    break;
                }
            }
        }
    }
    
    private function read($backendSock) {
        $backendId = (int) $backendSock;
        
        $data = @fread($backendSock, $this->ioGranularity);
        
        if ($data || $data === '0') {
            $this->parse($backendId, $data);
        } elseif (!is_resource($backendSock) || feof($backendSock)) {
            $this->handleDeadBackendSocket($backendId);
        }
    }
    
    private function parse($backendId, $data) {
        $parser = $this->backendParsers[$backendId];
        while ($responseArr = $parser->parse($data)) {
            $this->onResponse($backendId, $responseArr);
            $data = '';
        }
    }
    
    private function onResponse($backendId, array $responseArr) {
        $requestId = array_shift($this->responseQueue[$backendId]);
        $this->pendingRequestCounts[$backendId]--;
        
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
    }
    
    /**
     * @TODO Handle dead backend socket
     * @TODO Respond with 502 Bad Gateway to all pending requests for this backend
     * @TODO Attempt periodic reconnection to backend
     */
    private function handleDeadBackendSocket($backendId) {
        throw new \RuntimeException(
            'Backend socket has gone away'
        );
    }
    
    function setMaxPendingRequests($count) {
        $this->maxPendingRequests = filter_var($count, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'default' => 1500
        ]]);
    }
}

