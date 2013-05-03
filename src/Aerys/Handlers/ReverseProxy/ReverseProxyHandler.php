<?php

namespace Aerys\Handlers\ReverseProxy;

use Amp\Reactor,
    Aerys\Server,
    Aerys\Status,
    Aerys\Reason,
    Aerys\Parsing\MessageParser,
    Aerys\Parsing\PeclMessageParser;

/**
 * @TODO Implement non-blocking connection attempts for backend sockets
 * @TODO Implement optional TLS for backend socket connections
 * @TODO Implement exponential backoff for socket connection attempts
 */
class ReverseProxyHandler {
    
    private $reactor;
    private $server;
    private $backends = [];
    private $writableBackends;
    private $backendSubscriptions;
    private $maxPendingRequests = 1500;
    private $autoWriteInterval = 0.05;
    private $ioGranularity = 262144;
    
    function __construct(Reactor $reactor, Server $server, array $backends) {
        $this->reactor = $reactor;
        $this->server = $server;
        $this->writableBackends = new \SplObjectStorage;
        $this->backendSubscriptions = new \SplObjectStorage;
        $this->canUsePeclParser = extension_loaded('http');
        
        foreach ($backends as $backendUri) {
            $this->connect($backendUri);
        }
        
        $reactor->repeat(function() { $this->autoWrite(); }, $this->autoWriteInterval);
    }
    
    private function connect($uri) {
        if ($socket = @stream_socket_client($uri, $errNo, $errStr)) {
            stream_set_blocking($socket, FALSE);
            
            $parser = $this->canUsePeclParser
                ? new PeclMessageParser(MessageParser::MODE_RESPONSE)
                : new MessageParser(MessageParser::MODE_RESPONSE);
            
            $backend = new Backend($this->server, $parser, $socket);
            $readSubscription = $this->reactor->onReadable($socket, function() use ($backend) {
                $this->read($backend);
            });
            
            $this->backends[$uri] = $backend;
            $this->backendSubscriptions->attach($backend, $readSubscription);
            
            $result = TRUE;
            
        } else {
            $errMsg = "Socket connect failure: $uri" . ($errNo ? "; [Error# $errNo] $errStr" : '');
            $stderr = $this->server->getErrorStream();
            fwrite($stderr, $errMsg);
            
            $result = FALSE;
        }
        
        return $result;
    }
    
    private function read(Backend $backend) {
        try {
            $backend->read();
        } catch (BackendGoneException $e) {
            $this->onDeadBackend($backend);
        }
    }
    
    private function onDeadBackend(Backend $backend) {
        $readSubscription = $this->backendSubscriptions->offsetGet($backend);
        $readSubscription->cancel();
        $this->backendSubscriptions->detach($backend);
        $this->writableBackends->detach($backend);
        $uri = $backend->getUri();
        unset($this->backends[$uri]);
        $this->connect($uri);
    }
    
    private function autoWrite() {
        foreach ($this->writableBackends as $backend) {
            $this->write($backend);
        }
    }
    
    private function write(Backend $backend) {
        try {
            if ($backend->write()) {
                $this->writableBackends->detach($backend);
            }
        } catch (BackendGoneException $e) {
            $this->onDeadBackend($backend);
        }
    }
    
    function __invoke($asgiEnv, $requestId) {
        if (!$this->backends) {
            $asgiResponse = $this->generateServiceUnavailableResponse();
            $this->server->setResponse($requestId, $asgiResponse);
            return;
        }
        
        $backends = array_map([$this, 'mapBackendQueueSize'], $this->backends);
        asort($backends);
        
        if (current($backends) < $this->maxPendingRequests) {
            $uri = key($backends);
            $backend = $this->backends[$uri];
            $backend->enqueueRequest($requestId, $asgiEnv);
            $this->write($backend);
        } else {
            $asgiResponse = $this->generateServiceUnavailableResponse();
            $this->server->setResponse($requestId, $asgiResponse);
        }
    }
    
    private function generateServiceUnavailableResponse() {
        $status = Status::SERVICE_UNAVAILABLE;
        $reason = Reason::HTTP_503;
        $body = "<html><body><h1>$status $reason</h1><hr /></body></html>";
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Length' => strlen($body)
        ];
        
        return [$status, $reason, $headers, $body];
    }
    
    private function mapBackendQueueSize(Backend $backend) {
        return $backend->getQueueSize();
    }
    
    function setMaxPendingRequests($count) {
        $this->maxPendingRequests = filter_var($count, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'default' => 1500
        ]]);
    }
    
    function __destruct() {
        foreach ($this->backendSubscriptions as $sub) {
            $sub->cancel();
        }
    }
}

