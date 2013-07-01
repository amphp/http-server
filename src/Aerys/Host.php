<?php

namespace Aerys;

class Host {
    
    private $address;
    private $port;
    private $name;
    private $handler;
    
    function __construct($address, $port, $name, callable $asgiAppHandler = NULL) {
        $this->address = $address;
        $this->port = (int) $port;
        $this->name = strtolower($name);
        $this->handler = $asgiAppHandler;
    }
    
    function getId() {
        return $this->name . ':' . $this->port;
    }
    
    function getAddress() {
        return $this->address;
    }
    
    function getPort() {
        return $this->port;
    }
    
    function getName() {
        return $this->name;
    }
    
    function getHandler() {
        return $this->handler;
    }
    
    function isWildcard() {
        return ($this->name === '*');
    }
    
}

