<?php

namespace Aerys\Http;

class Host {
    
    const WILDCARD = '*';
    
    private $interface;
    private $port;
    private $name;
    private $handler;
    
    function __construct($interface, $port, $name, callable $handler) {
        $this->port = (int) $port;
        $this->interface = $interface;
        $this->name = strtolower($name);
        $this->handler = $handler;
    }
    
    function getId() {
        return $this->name . ':' . $this->port;
    }
    
    function getName() {
        return $this->name;
    }
    
    function getInterface() {
        return $this->interface;
    }
    
    function hasWildcardInterface() {
        return ($this->interface == self::WILDCARD);
    }
    
    function getInterfaceId() {
        return $this->interface . ':' . $this->port;
    }
    
    function getPort() {
        return $this->port;
    }
    
    function getHandler() {
        return $this->handler;
    }
    
}

