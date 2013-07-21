<?php

namespace Aerys\Config;

use Auryn\Injector,
    Auryn\Provider,
    Auryn\InjectionException;

class Bootstrapper {
    
    const DEFAULT_MOD_PRIORITY = 50;
    const SERVER_CLASS = 'Aerys\Server';
    const HOST_CLASS = 'Aerys\Host';
    
    private $injector;
    private $modLauncherClassMap = [
        'log' => '\Aerys\Mods\Log\ModLogLauncher',
        'upgrade' => '\Aerys\Mods\Upgrade\ModUpgradeLauncher',
        'websocket' => '\Aerys\Mods\Websocket\ModWebsocketLauncher',
        'send-file' => '\Aerys\Mods\SendFile\ModSendFileLauncher',
        'error-pages' => '\Aerys\Mods\ErrorPages\ModErrorPagesLauncher',
        'expect' => '\Aerys\Mods\Expect\ModExpectLauncher',
        'limit' => '\Aerys\Mods\Limit\ModLimitLauncher'
    ];
    private $modPriorityKeys = [
        'onHeaders',
        'beforeResponse',
        'afterResponse'
    ];
    
    function __construct(Injector $injector = NULL) {
        $this->injector = $injector ?: new Provider;
    }
    
    /**
     * Complement or override the built-in mod configuration launchers
     * 
     * @param string $modKey The reference mod configuration key name to use inside host mod blocks
     * @param string $launcherClassName The name of the ModConfigLauncher class to use for mod instantiation
     * @throws \InvalidArgumentException On bad arguments
     * @return void
     */
    function mapModLauncher($modKey, $launcherClassName) {
        if (!(is_string($modKey) && strlen($modKey))) {
            throw new \InvalidArgumentException(
                'String mod key required at Argument 1 of ' .
                __CLASS__ . '::' . __METHOD__ 
            );
        } elseif (!(is_string($launcherClassName) && class_exists($launcherClassName))) {
            throw new \InvalidArgumentException(
                'Loadable ModConfigLauncher class name required at Argument 2 of ' .
                __CLASS__ . '::' . __METHOD__ 
            );
        } else {
            $this->modLauncherClassMap[$modKey] = $launcherClassName;
        }
        
        return $this;
    }
    
    /**
     * Generate a runnable server using the specified configuration
     * 
     * @param array $config A formatted server configuration array
     * @return \Aerys\Server A ready-to-run server instance
     */
    function createServer(array $config) {
        $serverOptions = isset($config['options']) ? $config['options'] : [];
        unset($config['options']);
        
        $this->makeEventReactor();
        
        $server = $this->injector->make(self::SERVER_CLASS);
        $this->injector->share($server);
        
        foreach ($this->generateHostDefinitions($config) as $host) {
            $server->registerHost($host);
        }
        
        foreach ($serverOptions as $key => $value) {
            $server->setOption($key, $value);
        }
        
        return $server;
    }
    
    private function makeEventReactor() {
        try {
            $reactor = $this->injector->make('Amp\Reactor');
        } catch (InjectionException $e) {
            $this->injector->delegate('Amp\Reactor', ['Amp\ReactorFactory', 'select']);
            $reactor = $this->injector->make('Amp\Reactor');
        }
        
        $this->injector->alias('Amp\Reactor', get_class($reactor));
        $this->injector->share('Amp\Reactor');
    }
    
    private function generateHostDefinitions(array $hosts) {
        $hostDefinitions = [];
        
        foreach ($hosts as $key => $definitionArr) {
            if (empty($definitionArr['listenOn'])) {
                throw new ConfigException(
                    "Invalid host config; listenOn directive required in host key {$key}"
                );
            }
            
            if (empty($definitionArr['application'])) {
                throw new ConfigException(
                    "Invalid host config; application directive required in host key {$key}"
                );
            }
            
            list($address, $port) = $this->splitHostAddressAndPort($definitionArr['listenOn']);
            $name = ($hasName = !empty($definitionArr['name'])) ? $definitionArr['name'] : $address;
            $handler = $this->generateHostHandler($definitionArr['application']);
            
            $injectionDefinition = [
                ':address' => $address,
                ':port' => $port,
                ':name' => $name,
                ':asgiAppHandler' => $handler
            ];
            
            $host = $this->makeHost($injectionDefinition);
            
            if (!empty($definitionArr['tls'])) {
                $host->registerTlsDefinition($definitionArr['tls']);
            }
            
            if (!empty($definitionArr['mods'])) {
                $this->registerHostMods($host, $definitionArr['mods']);
            }
            
            $hostId = $host->getId();
            $wildcardHostId = "*:$port";
            
            if (isset($hostDefinitions[$hostId])) {
                throw new ConfigException(
                    "Host ID conflict detected: {$hostId} already exists"
                );
            }
            
            if (!$hasName && isset($hostDefinitions[$wildcardHostId])) {
                throw new ConfigException(
                    "Host ID conflict detected: {$hostId} already exists"
                );
            }
            
            $hostDefinitions[$hostId] = $host;
        }
        
        return $hostDefinitions;
    }
    
    private function makeHost(array $injectionDefinition) {
        try {
            return $this->injector->make(self::HOST_CLASS, $injectionDefinition);
        } catch (\Exception $previousException) {
            throw new ConfigException(
                'Failed instantiating host',
                $errorCode = 0,
                $previousException
            );
        }
    }
    
    private function generateHostHandler($handler) {
        if ($handler instanceof ConfigLauncher) {
            return $handler->launch($this->injector);
        } elseif (is_callable($handler)) {
            return $handler;
        } elseif (is_string($handler) && strlen($handler)) {
            return $this->provisionInjectableHandlerClass($handler);
        } else {
            throw new ConfigException(
                'Invalid host handler; callable, ConfigLauncher or injectable class name required'
            );
        }
    }
    
    private function provisionInjectableHandlerClass($handler) {
        try {
            return $this->injector->make($handler);
        } catch (InjectionException $injectionError) {
            throw new ConfigException(
                "Failed instantiating handler class {$handler}",
                $errorCode = 0,
                $injectionError
            );
        }
    }
    
    /**
     * @TODO Add "listenOn" IP:PORT format validation
     */
    private function splitHostAddressAndPort($listenOn) {
        $portStartPos = strrpos($listenOn, ':');
        $address = substr($listenOn, 0, $portStartPos);
        $port = substr($listenOn, $portStartPos + 1);
        
        return [$address, $port];
    }
    
    private function registerHostMods($host, array $modDefinitions) {
        foreach ($modDefinitions as $modKey => $modDefinition) {
            list($mod, $priorityMap) = $this->buildModFromDefinitionArray($modKey, $modDefinition);
            $host->registerMod($mod, $priorityMap);
        }
    }
    
    private function buildModFromDefinitionArray($modKey, $modDefinition) {
        if (!isset($this->modLauncherClassMap[$modKey])) {
            throw new ConfigException(
                "Invalid mod configuration key; no launcher mapped for {$modKey}"
            );
        }
        
        $modLauncherClass = $this->modLauncherClassMap[$modKey];
        $modLauncher = $this->instantiateModLauncher($modLauncherClass, $modDefinition);
        
        if (!$modLauncher instanceof ModConfigLauncher) {
            throw new ConfigException(
                "Invalid mod launcher; instance of Aerys\Config\ModConfigLauncher required"
            );
        }
        
        $mod = $modLauncher->launch($this->injector);
        
        $modPriorityMap = $modLauncher->getModPriorityMap() ?: [];
        
        if (!is_array($modPriorityMap)) {
            throw new ConfigException(
                "Invalid mod priority map returned by {$modClass}; array required"
            );
        }
        
        $modPriorityMap = array_intersect_key($modPriorityMap, array_flip($this->modPriorityKeys));
        $modPriorityMap = array_map([$this, 'normalizeModPriorityValue'], $modPriorityMap);
        
        return [$mod, $modPriorityMap];
    }
    
    private function instantiateModLauncher($modLauncherClass, array $modDefinition) {
        try {
            return $this->injector->make($modLauncherClass, [':config' => $modDefinition]);
        } catch (InjectionException $injectionError) {
            throw new ConfigException(
                "Failed instantiating mod launcher {$modClass}",
                $errorCode = 0,
                $injectionError
            );
        }
    }
    
    private function normalizeModPriorityValue($int) {
        return filter_var($int, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'max_range' => 100,
            'default' => self::DEFAULT_MOD_PRIORITY
        ]]);
    }
}
