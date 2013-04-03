<?php

namespace Aerys\Handlers\Websocket;

use Amp\Reactor;

class SessionManager implements \Countable {
    
    private $reactor;
    private $sessionFactory;
    
    private $sessions;
    private $awaitingClose;
    private $awaitingWrite;
    
    private $readTimeout = 3;
    private $closeTimeout = 5;
    private $autoWriteInterval = 0.025;
    private $autoCloseInterval = 1;
    
    private $clientCounts = [];
    private $heartbeatPeriods = [];
    
    function __construct(Reactor $reactor, SessionFactory $sessionFactory = NULL) {
        $this->reactor = $reactor;
        
        $this->sessionFactory = $sessionFactory ?: new SessionFactory;
        
        $this->sessions = new \SplObjectStorage;
        $this->awaitingWrite = new \SplObjectStorage;
        $this->awaitingClose = new \SplObjectStorage;
        
        $reactor->repeat([$this, 'autoWrite'], $this->autoWriteInterval);
        $reactor->repeat([$this, 'autoClose'], $this->autoCloseInterval);
    }
    
    function open($socket, Endpoint $endpoint, array $asgiEnv) {
        $session = $this->sessionFactory->make($socket, $this, $endpoint, $asgiEnv);
        
        $requestUri = $asgiEnv['REQUEST_URI'];
        $endpointOpts = $endpoint->getOptions();
        
        if (isset($this->clientCounts[$requestUri])) {
            $this->clientCounts[$requestUri]++;
        } else {
            $this->clientCounts[$requestUri] = 1;
            $this->heartbeatPeriods[$requestUri] = $endpointOpts->getHeartbeatPeriod();
        }
        
        $readTimeout = $this->heartbeatPeriods[$requestUri]
            ? $this->heartbeatPeriods[$requestUri]
            : $this->readTimeout;
        
        $subscription = $this->reactor->onReadable($socket, [$session, 'read'], $readTimeout);
        $this->sessions->attach($session, [$socket, $subscription]);
        
        $session->setOptions($endpointOpts);
        $session->open();
    }
    
    function autoWrite(Session $session = NULL) {
        if ($session) {
            $this->awaitingWrite->attach($session);
        }
        
        foreach ($this->awaitingWrite as $session) {
            if ($session->write()) {
                $this->awaitingWrite->detach($session);
            }
        }
    }
    
    function shutdownRead(Session $session) {
        list($socket, $subscription) = $this->sessions->offsetGet($session);
        $subscription->cancel();
        @stream_socket_shutdown($socket, STREAM_SHUT_RD);
    }
    
    function awaitClose(Session $session) {
        $socket = $this->sessions->offsetGet($session)[0];
        @stream_socket_shutdown($socket, STREAM_SHUT_WR);
        $this->awaitingClose->attach($session, time());
    }
    
    function autoClose() {
        $now = time();
        
        foreach ($this->awaitingClose as $session) {
            $closeTime = $this->awaitingClose->offsetGet($session);
            $timeElapsed = $now - $closeTime;
            
            if ($timeElapsed > $this->closeTimeout) {
                $this->awaitingClose->detach($session);
                $this->close($session);
            }
        }
    }
    
    function close(Session $session) {
        list($socket, $subscription) = $this->sessions->offsetGet($session);
        
        $subscription->cancel();
        
        if (is_resource($socket)) {
            @fclose($socket);
        }
        
        $requestUri = $session->getAsgiEnv()['REQUEST_URI'];
        $this->clientCounts[$requestUri]--;
        
        $this->sessions->detach($session);
        $this->awaitingWrite->detach($session);
    }
    
    function count($endpointUri = NULL) {
        return ($endpointUri && isset($this->clientCounts[$endpointUri]))
            ? $this->clientCounts[$endpointUri]
            : $this->sessions->count();
    }
    
}
