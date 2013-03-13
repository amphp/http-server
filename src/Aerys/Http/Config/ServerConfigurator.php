<?php

namespace Aerys\Http\Config;

use Auryn\Injector,
    Auryn\Provider,
    Aerys\Server,
    Aerys\TlsServer,
    Aerys\Reactor\Reactor,
    Aerys\Reactor\ReactorFactory,
    Aerys\Http\Host,
    Aerys\Http\HttpServer;

class ServerConfigurator {
    
    private $injector;
    private $reactorFactory;
    
    function __construct(Injector $injector = NULL, ReactorFactory $reactorFactory = NULL) {
        $this->injector = $injector ?: new Provider;
        $this->reactorFactory = $reactorFactory ?: new ReactorFactory;
    }
    
    function createServer(array $config) {
        list($reactor, $opts, $tls, $globalMods, $hostConf) = $this->listConfigSections($config);
        
        $reactor = $this->generateReactor($reactor);
        $httpServer = new HttpServer($reactor);
        
        $this->injector->implement('Aerys\\Reactor\\Reactor', get_class($reactor));
        $this->injector->share($reactor);
        $this->injector->share($httpServer);
        
        $socketServers = [];
        $hosts = [];
        $mods = [];
        
        foreach ($tls as $interfaceId => $tlsArr) {
            $portStartPos = strrpos($interfaceId, ':');
            $interface = substr($interfaceId, 0, $portStartPos);
            $port = substr($interfaceId, $portStartPos + 1);
            
            $server = new TlsServer(
                $reactor,
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
                $socketServers[$interfaceId] = new Server($reactor, $interface, $port);
            }
        }
        
        foreach ($socketServers as $server) {
            $httpServer->addServer($server);
        }
        
        foreach ($hosts as $host) {
            $httpServer->addHost($host);
        } 
        
        foreach ($opts as $key => $value) {
            $httpServer->setOption($key, $value);
        }
        
        $this->registerMods($httpServer, $globalMods, $mods);
        $this->registerErrorHandler($httpServer);
        
        return $httpServer;
    }
    
    private function listConfigSections(array $config) {
        $reactor = $opts = $tls = $mods = $hosts = [];
        
        if (isset($config['globals']['reactor'])) {
            $reactor = $config['globals']['reactor'];
        }
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
        
        $hosts = $config;
        
        return [$reactor, $opts, $tls, $mods, $hosts];
    }
    
    private function generateReactor($reactor) {
        if (!$reactor) {
            return $this->reactorFactory->select();
        } elseif ($reactor instanceof Reactor) {
            return $reactor;
        } else {
            throw new ConfigException(
                'Invalid global reactor specified; Aerys\\Reactor\\Reactor instance expected'
            );
        }
    }
    
    private function generateHostDefinitions(array $hosts) {
        $hostDefinitions = [];
        
        foreach ($hosts as $hostDefinitionArr) {
            if (empty($hostDefinitionArr['listenOn']) || empty($hostDefinitionArr['application'])) {
                throw new ConfigException(
                    'Invalid host config; `listenOn` and `application` keys required'
                );
            }
            
            $handler = $this->generateHostHandler($hostDefinitionArr['application']);
            
            $interfaceId = $hostDefinitionArr['listenOn'];
            $portStartPos = strrpos($interfaceId, ':');
            $interface = substr($interfaceId, 0, $portStartPos);
            $port = substr($interfaceId, $portStartPos + 1);
            
            $name = empty($hostDefinitionArr['name']) ? $interface : $hostDefinitionArr['name'];
            $mods = isset($hostDefinitionArr['mods']) ? $hostDefinitionArr['mods'] : [];
            
            $host = new Host($interface, $port, $name, $handler);
            $hostDefinitions[$host->getId()] = [$host, $mods];
        }
        
        return $hostDefinitions;
    }
    
    private function generateHostHandler($handler) {
        if ($handler instanceof AppLauncher) {
            return $handler->launchApp($this->injector);
        } elseif (is_callable($handler)) {
            return $handler;
        } else {
            throw new ConfigException(
                'Invalid host handler; callable or AppLauncher instance required'
            );
        }
    }
    
    private function registerMods(HttpServer $server, $globalMods, $hostMods) {
        foreach ($globalMods as $modKey => $modDefinition) {
            $globalMods[$modKey] = $this->buildMod($modKey, $modDefinition);
        }
        
        foreach ($hostMods as $hostId => $hostModArr) {
            $mods = [];
            foreach ($hostModArr as $modKey => $modDefinition) {
                $mods[$modKey] = $this->buildMod($modKey, $modDefinition);
            }
            
            foreach (array_merge($globalMods, $mods) as $mod) {
                $server->registerMod($hostId, $mod);
            }
        }
    }
    
    private function buildMod($modKey, $modDefinition) {
        switch (strtolower($modKey)) {
            case 'sendfile':
                return $this->buildModSendfile($modDefinition);
            case 'log':
                return $this->buildModLog($modDefinition);
            case 'errorpages':
                return $this->buildModErrorPages($modDefinition);
            case 'limit':
                return $this->buildModLimit($modDefinition);
            case 'expect':
                return $this->buildModExpect($modDefinition);
            case 'websocket':
                return $this->buildModWebsocket($modDefinition);
            default:
                throw new ConfigException(
                    'Invalid mod key specified: ' . $modKey
                );
        }
    }
    
    private function buildModSendfile(array $config) {
        $docRoot = $config['docRoot'];
        unset($config['docRoot']);
        
        $filesys = $this->injector->make('Aerys\\Http\\Filesys', [
            ':docRoot' => $docRoot,
            ':options' => $config
        ]);
        
        return $this->injector->make('Aerys\\Http\\Mods\\ModSendFile', [
            ':filesys' => $filesys
        ]);
    }
    
    private function buildModLog(array $config) {
        $logs = $config['logs'];
        unset($config['logs']);
        
        return $this->injector->make('Aerys\\Http\\Mods\\ModLog', [
            ':logs' => $logs,
            ':options' => $config
        ]);
    }
    
    private function buildModErrorPages(array $config) {
        return $this->injector->make('Aerys\\Http\\Mods\\ModErrorPages', [
            ':config' => $config
        ]);
    }
    
    private function buildModLimit(array $config) {
        return $this->injector->make('Aerys\\Http\\Mods\\ModLimit', [
            ':config' => $config
        ]);
    }
    
    private function buildModExpect(array $config) {
        return $this->injector->make('Aerys\\Http\\Mods\\ModExpect', [
            ':config' => $config
        ]);
    }
    
    private function buildModWebsocket(array $config) {
        $wsHandler = $this->injector->make('Aerys\\Ws\\Websocket', [
            ':endpoints' => $config
        ]);
        
        return $this->injector->make('Aerys\\Http\\Mods\\ModWebsocket', [
            ':wsHandler' => $wsHandler
        ]);
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
                
                //$errorStream = $server->getErrorStream();
                //fwrite($errorStream, $msg);
                throw new \ErrorException($msg);
            }
        });
    }
    
}

