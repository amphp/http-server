<?php

namespace Aerys;

use Aerys\Engine\EventBase;

class Server {
    
    private $eventBase;
    private $interface;
    private $port;
    private $socket;
    private $acceptSubscription;
    
    private $isBound = FALSE;
    
    function __construct(EventBase $eventBase, $interface, $port) {
        $this->eventBase = $eventBase;
        $this->setInterface($interface);
        $this->port = (int) $port;
    }
    
    private function setInterface($interface) {
        if (filter_var(trim($interface, '[]'), FILTER_VALIDATE_IP)) {
            $this->interface = $interface;
        } else {
            throw new \InvalidArgumentException(
                'Invalid server interface IP'
            );
        }
    }
    
    final function bind(callable $onClient) {
        if ($this->isBound) {
            return;
        }
        
        $bindOn = $this->interface . ':' . $this->port;
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        
        if ($socket = stream_socket_server($bindOn, $errNo, $errStr, $flags)) {
            stream_set_blocking($socket, FALSE);
            $this->socket = $socket;
            $this->acceptSubscription = $this->eventBase->onReadable($socket, function($socket) use ($onClient) {
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
    
    final function getInterface() {
        return $this->interface;
    }
    
    final function getPort() {
        return $this->port;
    }
    
}

