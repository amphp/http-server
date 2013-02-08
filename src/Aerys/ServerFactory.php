<?php

namespace Aerys;

use Aerys\Engine\EventBase,
    Aerys\Engine\LibEventBase;

class ServerFactory {
    
    private $modFactories = [
        'mod.log'       => ['Aerys\\Mods\\Log', 'createMod'],
        'mod.errorpages'=> ['Aerys\\Mods\\ErrorPages', 'createMod'],
        'mod.sendfile'  => ['Aerys\\Mods\\SendFile', 'createMod']
    ];
    
    function createServer(array $config) {
        list($opts, $tls, $globalMods, $hosts) = $this->listConfigSections($config);
        
        $mods = [];
        $vhosts = new VirtualHostGroup;
        
        foreach ($this->generateHostDefinitions($hosts) as $hostId => $hostStruct) {
            list($host, $hostMods) = $hostStruct;
            $vhosts->addHost($host);
            $mods[$hostId] = $hostMods;
        }
        
        $eventBase = $this->selectEventBase();
        $server = new Server($eventBase, $vhosts);
        
        foreach ($opts as $key => $value) {
            $server->setOption($key, $value);
        }
        
        foreach ($this->generateTlsDefinitions($tls) as $interfaceId => $definition) {
            $server->setTlsDefinition($interfaceId, $definition);
        }
        
        $this->registerMods($server, $eventBase, $globalMods, $mods);
        
        return $server;
    }
    
    private function listConfigSections(array $config) {
        $opts = $tls = $mods = $hosts = [];
        
        if (isset($config['globals']['opts'])) {
            $opts = $config['globals']['opts'];
        }
        if (isset($config['globals']['tls'])) {
            $tls = $config['globals']['tls'];
        }
        if (isset($config['globals']['mods'])) {
            $mods = $config['globals']['mods'];
        }
        
        unset($config['globals']);
        
        // Anything left in the config array should be a host definition
        
        return [$opts, $tls, $mods, $config];
    }
    
    /**
     * @TODO determine appropriate exception to throw on config errors
     */
    private function generateHostDefinitions(array $hosts) {
        $hostDefinitions = [];
        
        foreach ($hosts as $hostDefinitionArr) {
            if (!(empty($hostDefinitionArr['listen']) || empty($hostDefinitionArr['handler']))) {
                list($interface, $port) = explode(':', $hostDefinitionArr['listen']);
                $handler = $hostDefinitionArr['handler'];
            } else {
                throw new \RuntimeException;
            }
            
            $name = empty($hostDefinitionArr['name']) ? '127.0.0.1' : $hostDefinitionArr['name'];
            $mods = isset($hostDefinitionArr['mods']) ? $hostDefinitionArr['mods'] : [];
            
            $host = new Host($interface, $port, $name, $handler);
            $hostDefinitions[$host->getId()] = [$host, $mods];
        }
        
        return $hostDefinitions;
    }
    
    /**
     * @TODO select best available event base according to system availability
     */
    private function selectEventBase() {
        return new LibEventBase;
    }
    
    private function registerMods(Server $server, EventBase $eventBase, $globalMods, $hostMods) {
        foreach ($globalMods as $modKey => $modDefinition) {
            $modFactory = $this->modFactories[$modKey];
            $globalMods[$modKey] = $modFactory($server, $eventBase, $modDefinition);
        }
        
        foreach ($hostMods as $hostId => $hostModArr) {
            $mods = [];
            foreach ($hostModArr as $modKey => $modDefinition) {
                $modFactory = $this->modFactories[$modKey];
                $mods[$modKey] = $modFactory($server, $eventBase, $modDefinition);
            }
            
            foreach (array_merge($globalMods, $mods) as $mod) {
                $server->registerMod($hostId, $mod);
            }
        }
    }
    
    private function generateTlsDefinitions(array $tlsDefinitionArr) {
        $tlsDefs = [];
        
        foreach ($tlsDefinitionArr as $interfaceId => $definition) {
            if (!(isset($definition['localCertFile']) && isset($definition['certPassphrase']))) {
                throw new \InvalidArgumentException(
                    'Invalid TLS definition'
                );
            }
            
            $tlsDef = new TlsDefinition($definition['localCertFile'], $definition['certPassphrase']);
            unset($definition['localCertFile'], $definition['certPassphrase']);
            $tlsDef->setOptions($definition);
            
            $tlsDefs[$interfaceId] = $tlsDef;
        }
        
        return $tlsDefs;
    }
    
}

