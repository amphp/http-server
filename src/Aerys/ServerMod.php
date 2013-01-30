<?php

namespace Aerys;

use Ardent\DomainException;

class ServerMod {
    
    private $className;
    private $configKey;
    private $events = [];
    
    public function __construct($className, $configKey, array $events) {
        if (!class_exists($className)) {
            // throw
        }
        
        $this->className = $className;
        $this->configKey = $configKey;
        
        $acceptableEvents = [Server::ON_HEADERS, Server::ON_REQUEST, Server::ON_RESPONSE];
        foreach ($events as $event) {
            if (!in_array($event, $acceptableEvents)) {
                throw new DomainException;
            }
        }
        
        $this->events = array_unique($events);
    }
    
    public function getClassName() {
        return $this->className;
    }
    
    public function getConfigKey() {
        return $this->configKey;
    }
    
    public function getEvents() {
        return $this->events;
    }
    
    public function isRegisteredFor($event) {
        return in_array($event, $this->events);
    }
}
