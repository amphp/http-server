<?php

namespace Aerys;

class Host {
    
    const NIC_WILDCARD = '0.0.0.0';
    
    private $port;
    private $name;
    private $handler;
    private $interface;
    private $mods;
    
    function __construct(callable $handler, $name, $port = 80, $interface = '*', array $mods = []) {
        $this->handler = $handler;
        $this->name = $name;
        $this->port = (int) $port;
        $this->interface = ($interface == '*') ? self::NIC_WILDCARD : $interface;
        $this->mods = $mods;
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
    
    function getInterface() {
        return $this->interface;
    }
    
    function getMods() {
        return $this->mods;
    }
    
}

