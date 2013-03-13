<?php

namespace Aerys;

use Aerys\Reactor\Reactor;

class Server {
    
    private $reactor;
    private $interface;
    private $port;
    private $socket;
    private $acceptSubscription;
    
    private $isBound = FALSE;
    
    function __construct(Reactor $reactor, $interface, $port) {
        $this->reactor = $reactor;
        $this->setInterface($interface);
        $this->port = (int) $port;
    }
    
    private function setInterface($interface) {
        if (filter_var(trim($interface, '[]'), FILTER_VALIDATE_IP)) {
            $this->interface = $interface;
        } else {
            throw new \InvalidArgumentException(
                'Invalid server interface address'
            );
        }
    }
    
    /**
     * Listen on the defined INTERFACE:PORT, invoking the supplied callable on new connections
     * 
     * Applications must start the event reactor separately.
     * 
     * @param callable $onClient The callable to invoke when new connections are established
     * @return void
     */
    final function bind(callable $onClient) {
        if ($this->isBound) {
            return;
        }
        
        $bindOn = $this->interface . ':' . $this->port;
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        
        if ($socket = stream_socket_server($bindOn, $errNo, $errStr, $flags)) {
            stream_set_blocking($socket, FALSE);
            $this->socket = $socket;
            $this->acceptSubscription = $this->reactor->onReadable($socket, function($socket) use ($onClient) {
                $this->accept($socket, $onClient);
            });
            $this->isBound = TRUE;
        } else {
            throw new \RuntimeException(
                "Failed binding server on $bindOn: [Error# $errNo] $errStr"
            );
        }
    }
    
    protected function accept($socket, callable $onClient) {
        $serverName = stream_socket_get_name($socket, FALSE);
        
        while ($clientSock = @stream_socket_accept($socket, 0, $peerName)) {
            $onClient($clientSock, $peerName, $serverName);
        }
    }
    
    /**
     * Temporarily stop accepting new connections but do not unbind the socket
     */
    final function disable() {
        if ($this->isBound) {
            $this->acceptSubscription->disable();
        }
    }
    
    /**
     * Resume accepting new connections on the bound socket
     */
    final function enable() {
        if ($this->isBound) {
            $this->acceptSubscription->enable();
        }
    }
    
    /**
     * Stop accepting client connections and unbind the server
     */
    final function stop() {
        if ($this->isBound) {
            $this->acceptSubscription->cancel();
            $this->acceptSubscription = NULL;
            $this->isBound = FALSE;
            
            fclose($this->socket);
        }
    }
    
    /**
     * Retrieve the server's interface address
     */
    final function getInterface() {
        return $this->interface;
    }
    
    /**
     * Retrieve the port on which the server listens
     */
    final function getPort() {
        return $this->port;
    }
    
}

