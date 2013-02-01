<?php

namespace Aerys;

/**
 * The HostCollection is essentially an SplObjectStorage that only allows the attachment of Host
 * instances. Additionally, it provides the capability to "select a virtual host" definition based
 * on a specified host name, server NIC interface and port. To make this host selection lookup as
 * fast as possible the primary hosts for a given set of name/interface/port values are cached.
 */
class HostCollection implements \Iterator, \Countable {
    
    private $hosts;
    private $nameMap = [];
    private $interfaceMap = [];
    private $portMap = [];
    
    function __construct() {
        $this->hosts = new \SplObjectStorage;
    }
    
    /**
     * If the specified name matches one of the Host instances in the collection that match is used.
     * If no match is found or no name value is specified the first Host match for the specified NIC
     * interface and port is returned.
     */
    function selectHost($name, $interface, $port) {
        $name = (FALSE !== strpos($name, ':')) ? $name : "$name:$port";
        $name = strtolower($name);
        
        if (isset($this->nameMap[$name])) {
            return $this->nameMap[$name];
        }
        
        $interface = $interface . ':' . $port;
        
        if (isset($this->interfaceMap[$interface])) {
            return $this->interfaceMap[$interface];
        } else {
            return $this->portMap[$port];
        }
    }
    
    function count() {
        return $this->hosts->count();
    }
    
    function attach(Host $host) {
        $this->hosts->attach($host);
        $port = $host->getPort();
        $mapName = strtolower($host->getName()) . ':' . $port;
        $this->nameMap[$mapName] = $host;
        
        $interface = $host->getInterface();
        $isWildcardNic = $interface !== Host::NIC_WILDCARD;
        
        if ($interface != Host::NIC_WILDCARD) {
            $interface .= ":$port";
            $this->interfaceMap[$interface] = $host;
        }
        
        if (!isset($this->portMap[$port])) {
            $this->portMap[$port] = $host;
        }
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
