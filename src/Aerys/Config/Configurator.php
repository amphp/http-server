<?php

namespace Aerys\Config;

use Auryn\Injector,
    Auryn\Provider,
    Auryn\InjectionException,
    Amp\TcpServer,
    Aerys\Host,
    Aerys\Server;

class Configurator {
    
    private $injector;
    
    function __construct(Injector $injector = NULL) {
        $this->injector = $injector ?: new Provider;
    }
    
    function createServer(array $config) {
        $tcpServers = $hosts = $mods = $options = [];
        
        if (isset($config['options'])) {
            $options = $config['options'];
            unset($config['options']);
        }
        
        $httpServer = $this->generateHttpServerInstance();
        $hostDefs = $this->generateHostDefinitions($config);
        
        foreach ($hostDefs as $hostId => $hostStruct) {
            list($host, $hostMods, $hostTls) = $hostStruct;
            
            $hosts[$hostId] = $host;
            $mods[$hostId] = $hostMods;
            $tcpId = $host->getAddress() . ':' . $host->getPort();
            $tcpServers[$tcpId] = $hostTls;
        }
        
        foreach ($tcpServers as $name => $tls) {
            $httpServer->defineBinding($name, $tls);
        }
        
        foreach ($hosts as $host) {
            $httpServer->addHost($host);
        }
        
        foreach ($options as $key => $value) {
            if (isset($value)) {
                $httpServer->setOption($key, $value);
            }
        }
        
        $this->registerMods($httpServer, $mods);
                
        return $httpServer;
    }
    
    private function generateHttpServerInstance() {
        $this->injector->delegate('Amp\Reactor', 'Amp\ReactorFactory');
        $reactor = $this->injector->make('Amp\Reactor');
        
        $this->injector->alias('Amp\Reactor', get_class($reactor));
        $this->injector->share($reactor);
        
        $httpServer = $this->injector->make('Aerys\Server');
        $this->injector->share($httpServer);
        
        return $httpServer;
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
        if ($handler instanceof Launcher) {
            return $handler->launchApp($this->injector);
        } elseif (is_callable($handler)) {
            return $handler;
        } else {
            throw new ConfigException(
                'Invalid host handler; callable or Launcher instance required'
            );
        }
    }
    
    private function registerMods(Server $httpServer, $hostMods) {
        foreach ($hostMods as $hostId => $hostModArr) {
            foreach ($hostModArr as $modKey => $modDefinition) {
                $mod = $this->buildMod($modKey, $modDefinition);
                $httpServer->registerMod($hostId, $mod);
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
        
        $handler = $this->injector->make('Aerys\Handlers\DocRoot\DocRootHandler', [
            ':docRoot' => $docRoot,
            ':options' => $config
        ]);
        
        return $this->injector->make('Aerys\Mods\SendFile\ModSendFile', [
            ':docRootHandler' => $handler
        ]);
    }
    
    private function buildModLog(array $config) {
        return $this->injector->make('Aerys\Mods\Log\ModLog', [
            ':logs' => $config
        ]);
    }
    
    private function buildModErrorPages(array $config) {
        return $this->injector->make('Aerys\Mods\ErrorPages\ModErrorPages', [
            ':config' => $config
        ]);
    }
    
    private function buildModLimit(array $config) {
        return $this->injector->make('Aerys\Mods\Limit\ModLimit', [
            ':config' => $config
        ]);
    }
    
    private function buildModExpect(array $config) {
        return $this->injector->make('Aerys\Mods\Expect\ModExpect', [
            ':config' => $config
        ]);
    }
    
    private function buildModWebsocket(array $config) {
        $handler = $this->injector->make('Aerys\Handlers\Websocket\WebsocketHandler', [
            ':endpoints' => $config
        ]);
        
        return $this->injector->make('Aerys\Mods\Websocket\ModWebsocket', [
            ':websocketHandler' => $handler
        ]);
    }
}

