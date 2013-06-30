<?php

namespace Aerys\Handlers\ReverseProxy;

use Amp\Reactor,
    Aerys\Server,
    Aerys\Status,
    Aerys\Reason,
    Aerys\Parsing\MessageParser,
    Aerys\Parsing\PeclMessageParser;

class ReverseProxyHandler {
    
    private $reactor;
    private $server;
    private $backends = [];
    private $writableBackends;
    private $backendSubscriptions;
    private $pendingBackendSubscriptions = [];
    private $connectionAttempts = [];
    private $backendConnectTimeout = 5;
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
        
        $reactor->schedule(function() { $this->autoWrite(); }, $this->autoWriteInterval);
    }
    
    private function connect($uri) {
        $unusedTimeout = 42; // <--- not applicable in conjunction with STREAM_CLIENT_ASYNC_CONNECT flag
        $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;
        $socket = @stream_socket_client($uri, $errNo, $errStr, $unusedTimeout, $flags);
        
        if ($socket || $errNo === SOCKET_EWOULDBLOCK) {
            $this->schedulePendingBackendTimeout($uri, $socket);
        } else {
            $errMsg = "Connection to proxy backend {$uri} failed: [{$errNo}] {$errStr}\n";
            fwrite($this->server->getErrorStream(), $errMsg);
            $this->exponentialBackoff($uri);
        }
    }
    
    private function schedulePendingBackendTimeout($uri, $socket) {
        $subscription = $this->reactor->onWritable($socket, function($socket, $trigger) use ($uri) {
            $this->determinePendingConnectionResult($uri, $socket, $trigger);
        }, $this->backendConnectTimeout);
        
        $this->pendingBackendSubscriptions[$uri] = $subscription;
    }
    
    private function determinePendingConnectionResult($uri, $socket, $trigger) {
        $subscription = $this->pendingBackendSubscriptions[$uri];
        $subscription->cancel();
        
        unset($this->pendingBackendConnections[$uri]);
        
        if ($trigger === Reactor::TIMEOUT) {
            $errMsg = "Connection to proxy backend {$uri} failed: connect attempt timed out\n";
            fwrite($this->server->getErrorStream(), $errMsg);
            $this->exponentialBackoff($uri);
        } elseif ($this->workaroundAsyncConnectBug($socket)) {
            $this->finalizeNewBackendConnection($uri, $socket);
        } else {
            $errMsg = "Connection to proxy backend {$uri} failed\n";
            fwrite($this->server->getErrorStream(), $errMsg);
            $this->exponentialBackoff($uri);
        }
    }
    
    /**
     * This function exists to workaround asynchronously connected sockets that are erroneously
     * reported as writable when the connection actually failed. RFC 2616 requires servers to ignore
     * leading \r\n characters before the start of a message so we can get away with sending such
     * characters as a connectivity test.
     * 
     * @link https://bugs.php.net/bug.php?id=64803
     */
    private function workaroundAsyncConnectBug($socket) {
        return (@fwrite($socket, "\n") === 1);
    }
    
    private function exponentialBackoff($uri) {
        if (isset($this->connectionAttempts[$uri])) {
            $maxWait = ($this->connectionAttempts[$uri] * 2) - 1;
            $this->connectionAttempts[$uri]++;
        } else {
            $this->connectionAttempts[$uri] = $maxWait = 1;
        }
        
        if ($secondsUntilRetry = rand(0, $maxWait)) {
            $reconnect = function() use ($uri) { $this->connect($uri); };
            $this->reactor->once($reconnect, $secondsUntilRetry);
        } else {
            $this->connect($uri);
        }
    }
    
    private function finalizeNewBackendConnection($uri, $socket) {
        stream_set_blocking($socket, FALSE);
        
        $parser = $this->canUsePeclParser
            ? new PeclMessageParser(MessageParser::MODE_RESPONSE)
            : new MessageParser(MessageParser::MODE_RESPONSE);
        
        $parser->setOptions([
            'maxHeaderBytes' => 0,
            'maxBodyBytes' => 0
        ]);
        
        $backend = new Backend($this->server, $parser, $socket, $uri);
        $readSubscription = $this->reactor->onReadable($socket, function() use ($backend) {
            $this->read($backend);
        });
        
        $this->backends[$uri] = $backend;
        $this->backendSubscriptions->attach($backend, $readSubscription);
        
        unset($this->connectionAttempts[$uri]);
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
        foreach ($this->backendSubscriptions as $backend) {
            $sub = $this->backendSubscriptions->offsetGet($backend);
            $sub->cancel();
        }
    }
}

