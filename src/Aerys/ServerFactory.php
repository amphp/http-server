<?php

namespace Aerys;

use Aerys\InitHandler,
    Aerys\Engine\EventBase,
    Aerys\Engine\LibEventBase;

class ServerFactory {
    
    private $modClasses = [
        'mod.log'       => 'Aerys\\Mods\\Log',
        'mod.errorpages'=> 'Aerys\\Mods\\ErrorPages',
        'mod.sendfile'  => 'Aerys\\Mods\\SendFile',
        'mod.limit'     => 'Aerys\\Mods\\Limit'
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
        
        $this->registerMods($server, $globalMods, $mods);
        
        foreach ($vhosts as $host) {
            $handler = $host->getHandler();
            if ($handler instanceof InitHandler) {
                $handler->init($server, $eventBase);
            }
        }
        
        $this->registerErrorHandler($server);
        
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
    
    private function registerMods(Server $server, $globalMods, $hostMods) {
        foreach ($globalMods as $modKey => $modDefinition) {
            $modClass = $this->modClasses[$modKey];
            $mod = new $modClass;
            $mod->configure($modDefinition);
            $globalMods[$modKey] = $mod;
        }
        
        foreach ($hostMods as $hostId => $hostModArr) {
            $mods = [];
            foreach ($hostModArr as $modKey => $modDefinition) {
                $modClass = $this->modClasses[$modKey];
                $mod = new $modClass;
                $mod->configure($modDefinition);
                $mods[$modKey] = $mod;
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
    
    private function registerErrorHandler(Server $server) {
        error_reporting(E_ALL);
        set_error_handler(function($errNo, $errStr, $errFile, $errLine) use ($server) {
            if (error_reporting()) {
                switch ($errNo) {
                    case 1:     $errType = 'E_ERROR'; break;
                    case 2:     $errType = 'E_WARNING'; break;
                    case 4:     $errType = 'E_PARSE'; break;
                    case 8:     $errType = 'E_NOTICE'; break;
                    case 256:   $errType = 'E_USER_ERROR'; break;
                    case 512:   $errType = 'E_USER_WARNING'; break;
                    case 1024:  $errType = 'E_USER_NOTICE'; break;
                    case 2048:  $errType = 'E_STRICT'; break;
                    case 4096:  $errType = 'E_RECOVERABLE_ERROR'; break;
                    case 8192:  $errType = 'E_DEPRECATED'; break;
                    case 16384: $errType = 'E_USER_DEPRECATED'; break;
                    
                    default:    $errType = 'PHP ERROR'; break;
                }
                
                $msg = "[$errType]: $errStr in $errFile on line $errLine" . PHP_EOL;
                
                $errorStream = $server->getErrorStream();
                
                fwrite($errorStream, $msg);
            }
        });
    }
    
}

