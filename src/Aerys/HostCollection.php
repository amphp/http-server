<?php

namespace Aerys;

/**
 * The HostCollection is essentially an SplObjectStorage that only allows the attachment of Host
 * instances. Additionally, it provides the capability to "select a virtual host" definition based
 * on a specified host name, server NIC interface and port.
 */
class HostCollection implements \Iterator, \Countable {
    
    private $hosts;
    private $hostNameMap = [];
    
    function __construct() {
        $this->hosts = new \SplObjectStorage;
    }
    
    /**
     * If the specified name matches one of the Host instances in the collection that match is used.
     * If no match is found or no name value is specified the first Host match for the specified NIC
     * interface and port is returned.
     * 
     * @todo Determine correct exception to throw when no host match exists
     */
    function selectHost($name, $interface, $port) {
        $name = strtolower(@parse_url($name, PHP_URL_HOST)) . ':' . $port;
        
        if (isset($this->hostNameMap[$name])) {
            return $this->hostNameMap[$name];
        }
        
        foreach ($this->hosts as $host) {
            if (!$port == $host->getPort()) {
                continue;
            } elseif ($host->getInterface() == $interface || $interface == Host::NIC_WILDCARD) {
                return $host;
            }
        }
        
        throw new \DomainException;
    }
    
    function count() {
        return $this->hosts->count();
    }
    
    function attach(Host $host) {
        $this->hosts->attach($host);
        $mapName = strtolower($host->getName()) . ':' . $host->getPort();
        $this->hostNameMap[$mapName] = $host;
    }
    
    function detach(Host $host) {
        $this->hosts->detach($host);
        $mapName = strtolower($host->getName()) . ':' . $host->getPort();
        unset($this->hostNameMap[$mapName]);
    }
    
    function contains(Host $host) {
        return $this->hosts->contains($host);
    }
    
    function current() {
        return $this->hosts->current();
    }

    function key() {
        return $this->hosts->key();
    }

    function next() {
        $this->hosts->next();
    }

    function rewind() {
        $this->hosts->rewind();
    }

    function valid() {
        return $this->hosts->valid();
    }
    
}
