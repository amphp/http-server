<?php

namespace Aerys\Http;

use Aerys\Server,
    Aerys\TlsServer,
    Aerys\Engine\EventBase,
    Aerys\Engine\LibEventBase;

class HttpServerFactory {
    
    const WILDCARD = '*';
    const IPV4_MATCH_ALL = '0.0.0.0';
    const IPV6_MATCH_ALL = '[::]';
    const IPV4_LOOPBACK = '127.0.0.1';
    const IPV6_LOOPBACK = '[::]:1';
    
    private $modClasses = [
        'mod.log'       => 'Aerys\\Http\\Mods\\Log',
        'mod.errorpages'=> 'Aerys\\Http\\Mods\\ErrorPages',
        'mod.sendfile'  => 'Aerys\\Http\\Mods\\SendFile',
        'mod.limit'     => 'Aerys\\Http\\Mods\\Limit',
        'mod.expect'    => 'Aerys\\Http\\Mods\\Expect'
    ];
    
    function createServer(array $config) {
        list($opts, $tls, $globalMods, $hostConf) = $this->listConfigSections($config);
        
        $eventBase = $this->selectEventBase();
        
        $socketServers = [];
        $hosts = [];
        $mods = [];
        
        foreach ($tls as $interfaceId => $tlsArr) {
            $portStartPos = strrpos($interfaceId, ':');
            $interface = substr($interfaceId, 0, $portStartPos);
            $port = substr($interfaceId, $portStartPos + 1);
            
            $server = new TlsServer(
                $eventBase,
                $interface,
                $port,
                $tlsArr['localCertFile'],
                $tlsArr['certPassphrase']
            );
            
            unset(
                $tlsArr['localCertFile'],
                $tlsArr['certPassphrase']
            );
            
            foreach ($tlsArr as $option => $value) {
                $server->setOption($option, $value);
            }
            
            $socketServers[$interfaceId] = $server;
        }
        
        $hostDefs = $this->generateHostDefinitions($hostConf);
        
        foreach ($hostDefs as $hostId => $hostStruct) {
            list($host, $hostMods) = $hostStruct;
            $hosts[$hostId] = $host;
            $mods[$hostId] = $hostMods;
            
            $interface = $host->getInterface();
            $port = $host->getPort();
            $interfaceId = $interface . ':' . $port;
            
            if (!isset($socketServers[$interfaceId])) {
                $socketServers[$interfaceId] = new Server($eventBase, $interface, $port);
            }
        }
        
        $httpServer = new HttpServer($eventBase, $socketServers, $hosts);
        
        foreach ($opts as $key => $value) {
            $httpServer->setOption($key, $value);
        }
        
        $this->registerMods($httpServer, $globalMods, $mods);
        
        foreach ($hosts as $host) {
            $handler = $host->getHandler();
            if ($handler instanceof InitHandler) {
                $handler->init($httpServer, $eventBase);
            }
        }
        
        $this->registerErrorHandler($httpServer);
        
        return $httpServer;
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
                $interfaceId = $hostDefinitionArr['listen'];
                $portStartPos = strrpos($interfaceId, ':');
                $interface = substr($interfaceId, 0, $portStartPos);
                $port = substr($interfaceId, $portStartPos + 1);
                
                $handler = $hostDefinitionArr['handler'];
            } else {
                throw new \RuntimeException;
            }
            
            $name = empty($hostDefinitionArr['name']) ? $interface : $hostDefinitionArr['name'];
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
    
    private function registerMods(HttpServer $server, $globalMods, $hostMods) {
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
    
    private function registerErrorHandler(HttpServer $server) {
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
                throw new \ErrorException($msg, $errNo);
            }
        });
    }
    
}

