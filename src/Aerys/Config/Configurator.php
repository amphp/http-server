<?php

namespace Aerys\Config;

use Auryn\Injector,
    Auryn\Provider,
    Amp\Reactor,
    Amp\ReactorFactory,
    Amp\TcpServer,
    Aerys\Host,
    Aerys\Server;

class Configurator {
    
    private $injector;
    private $reactorFactory;
    
    function __construct(Injector $injector = NULL, ReactorFactory $reactorFactory = NULL) {
        $this->injector = $injector ?: new Provider;
        $this->reactorFactory = $reactorFactory ?: new ReactorFactory;
    }
    
    function createServer(array $config) {
        list($reactor, $opts, $globalMods, $hostConf) = $this->listConfigSections($config);
        
        $reactor = $this->generateReactor($reactor);
        $httpServer = new Server($reactor);
        
        $this->injector->implement('Amp\\Reactor', get_class($reactor));
        $this->injector->share($reactor);
        $this->injector->share($httpServer);
        
        $servers = [];
        $hosts = [];
        $mods = [];
        
        $hostDefs = $this->generateHostDefinitions($hostConf);
        
        foreach ($hostDefs as $hostId => $hostStruct) {
            list($host, $hostMods, $tls) = $hostStruct;
            
            $hosts[$hostId] = $host;
            $mods[$hostId] = $hostMods;
            
            $addr = $host->getAddress();
            $port = $host->getPort();
            $interfaceId = $addr . ':' . $port;
            
            $servers[$interfaceId] = $tls;
        }
        
        foreach ($servers as $name => $tls) {
            $httpServer->defineBinding($name, $tls);
        }
        
        foreach ($hosts as $host) {
            $httpServer->addHost($host);
        }
        
        foreach ($opts as $key => $value) {
            if (isset($value)) {
                $httpServer->setOption($key, $value);
            }
        }
        
        $this->registerMods($httpServer, $globalMods, $mods);
        $this->registerErrorHandler($httpServer);
        
        return $httpServer;
    }
    
    private function listConfigSections(array $config) {
        $reactor = $opts = $mods = $hosts = [];
        
        if (!empty($config['reactor'])) {
            $reactor = $config['reactor'];
        }
        if (isset($config['options'])) {
            $opts = $config['options'];
        }
        if (isset($config['mods'])) {
            $mods = $config['mods'];
        }
        
        unset(
            $config['reactor'],
            $config['options'],
            $config['mods']
        );
        
        $hostConfs = $config;
        
        return [$reactor, $opts, $mods, $hostConfs];
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
        
        foreach ($hosts as $key => $hostDefinitionArr) {
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
            
            if ($hasName = !empty($hostDefinitionArr['name'])) {
                $name = $hostDefinitionArr['name'];
            } else {
                $name = $interface;
            }
            
            $mods = isset($hostDefinitionArr['mods']) ? $hostDefinitionArr['mods'] : [];
            
            $host = new Host($interface, $port, $name, $handler);
            $hostId = $host->getId();
            
            $wildcardHostId = "*:$port";
            
            if (isset($hostDefinitions[$hostId])) {
                throw new ConfigException(
                    'Invalid host definition; host ID ' . $hostId . ' already exists'
                );
            } elseif (!$hasName && isset($hostDefinitions[$wildcardHostId])) {
                throw new ConfigException(
                    'Invalid host definition; unnamed host ID ' . $hostId . ' conflicts with ' .
                    'previously defined host: ' . $wildcardHostId
                );
            }
            
            $tls = empty($hostDefinitionArr['tls']) ? [] : $hostDefinitionArr['tls'];
            
            $hostDefinitions[$hostId] = [$host, $mods, $tls];
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
    
    private function registerMods(Server $server, $globalMods, $hostMods) {
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
        
        $filesys = $this->injector->make('Aerys\\Handlers\\StaticFiles\\Handler', [
            ':docRoot' => $docRoot,
            ':options' => $config
        ]);
        
        return $this->injector->make('Aerys\\Mods\\ModSendFile', [
            ':filesys' => $filesys
        ]);
    }
    
    private function buildModLog(array $config) {
        $logs = $config['logs'];
        unset($config['logs']);
        
        return $this->injector->make('Aerys\\Mods\\ModLog', [
            ':logs' => $logs,
            ':options' => $config
        ]);
    }
    
    private function buildModErrorPages(array $config) {
        return $this->injector->make('Aerys\\Mods\\ModErrorPages', [
            ':config' => $config
        ]);
    }
    
    private function buildModLimit(array $config) {
        return $this->injector->make('Aerys\\Mods\\ModLimit', [
            ':config' => $config
        ]);
    }
    
    private function buildModExpect(array $config) {
        return $this->injector->make('Aerys\\Mods\\ModExpect', [
            ':config' => $config
        ]);
    }
    
    private function buildModWebsocket(array $config) {
        $wsHandler = $this->injector->make('Aerys\\Handlers\\Websocket\\Handler', [
            ':endpoints' => $config
        ]);
        
        return $this->injector->make('Aerys\\Mods\\ModWebsocket', [
            ':wsHandler' => $wsHandler
        ]);
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
                //throw new \ErrorException($msg);
            }
        });
    }
    
}

