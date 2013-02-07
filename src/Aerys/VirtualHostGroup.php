<?php

namespace Aerys;

class VirtualHostGroup implements \Iterator, \Countable {
    
    private $hosts = [];
    private $primaryInterfaceIdMap = [];
    private $primaryPortMap = [];
    
    function addHost(Host $host) {
        $hostId = $host->getId();
        $this->hosts[$hostId] = $host;
        
        if (!$host->hasWildcardInterface()) {
            $this->primaryInterfaceIdMap[$host->getInterfaceId()] = $host;
        }
        
        $port = $host->getPort();
        
        if (!isset($this->primaryPortMap[$port])) {
            $this->primaryPortMap[$port] = $host;
        }
    }
    
    /**
     * If the specified name matches one of the Host instances in the collection that match is used.
     * If no match is found or no name value is specified the first Host match for the specified NIC
     * interface and port is returned.
     * 
     * @param string $name
     * @param string $interface
     * @param int $port
     */
    function selectHost($name, $interface, $port) {
        if (FALSE !== strpos($name, ':')) {
            $hostId = strtolower($name);
        } else {
            $hostId = strtolower("$name:$port");
        }
        
        $interfaceId = "$interface:$port";
        
        if (isset($this->hosts[$hostId])) {
            return $this->hosts[$hostId];
        } elseif (isset($this->primaryInterfaceIdMap[$interfaceId])) {
            return $this->primaryInterfaceIdMap[$interfaceId];
        } elseif (isset($this->primaryPortMap[$port])) {
            return $this->primaryPortMap[$port];
        } else {
            throw new \DomainException(
                'No hosts match the specified selection criteria'
            );
        }
    }
    
    function current() {
        return current($this->hosts);
    }

    function key() {
        return key($this->hosts);
    }

    function next() {
        return next($this->hosts);
    }

    function rewind() {
        return reset($this->hosts);
    }

    function valid() {
        return key($this->hosts) !== NULL;
    }
    
    function count() {
        return count($this->hosts);
    }
}

