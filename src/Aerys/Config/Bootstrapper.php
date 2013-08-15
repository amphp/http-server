<?php

namespace Aerys\Config;

use Auryn\Injector,
    Auryn\InjectorBuilder,
    Auryn\InjectionException,
    Aerys\Handlers\DocRoot\DocRootLauncher;

class Bootstrapper {
    
    const DEFAULT_MOD_PRIORITY = 50;
    const SERVER_CLASS = 'Aerys\Server';
    const HOST_CLASS = 'Aerys\Host';
    
    private $injector;
    private $injectorBuilder;
    private $beforeStartCallbacks;
    private $shortOpts = 'c:b:n:d:h';
    private $longOpts = [
        'config:',
        'bind:',
        'name:',
        'docroot:',
        'help'
    ];
    private $shortOptNameMap = [
        'c' => 'config',
        'b' => 'bind',
        'n' => 'name',
        'd' => 'docroot',
        'h' => 'help'
    ];
    private $modLauncherClassMap = [
        'log' => '\Aerys\Mods\Log\ModLogLauncher',
        'protocol' => '\Aerys\Mods\Protocol\ModProtocolLauncher',
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
    
    function __construct(InjectorBuilder $injectorBuilder = NULL) {
        $this->injectorBuilder = $injectorBuilder ?: new InjectorBuilder;
    }
    
    /**
     * Generate a server configuration array from CLI options
     * 
     * @throws \Aerys\Config\ConfigException
     * @return mixed Returns a server config array or NULL if -h or --help switches specified
     */
    function loadConfigFromCommandLine() {
        $options = getopt($this->shortOpts, $this->longOpts);

        return (isset($options['h']) || isset($options['help']))
            ? NULL
            : $this->loadConfig($options);
    }
    
    /**
     * Display a help screen
     * 
     * @return void
     */
    function displayHelp() {
        $helpLines = [
            'Example Usage:',
            '--------------',
            'php aerys.php --config="/path/to/server/config.php"',
            'php aerys.php --bind="*:80" --name="mysite.com" --root="/path/to/document/root"',
            PHP_EOL,
            'Options:',
            '--------',
            '-c, --config     Use a config file to bootstrap the server',
            '-b, --bind       The server\'s address and port (e.g. 127.0.0.1:80 or *:80)',
            '-n, --name       Optional host (domain) name',
            '-d, --docroot    The filesystem directory from which to serve static files',
            '-h, --help       Display help screen',
            PHP_EOL
        ];
        
        echo PHP_EOL, implode(PHP_EOL, $helpLines);
        
        return FALSE;
    }
    
    private function loadConfig(array $options) {
        $options = $this->normalizeOptionKeys($options);
        
        return $options['config']
            ? $this->generateConfigFromFile($options)
            : $this->generateDocRootConfig($options);
    }

    private function normalizeOptionKeys(array $options) {
        $normalizedOptions = [
            'config' => NULL,
            'bind' => NULL,
            'name' => NULL,
            'docroot' => NULL
        ];
        
        foreach ($options as $key => $value) {
            if (isset($this->shortOptNameMap[$key])) {
                $normalizedOptions[$this->shortOptNameMap[$key]] = $value;
            } else {
                $normalizedOptions[$key] = $value;
            }
        }
        
        return $normalizedOptions;
    }
    
    private function generateConfigFromFile(array $options) {
        $configFile = realpath($options['config']);
        
        if (!(is_file($configFile) && is_readable($configFile))) {
            throw new ConfigException(
                "Config file could not be read: {$configFile}"
            );
        }
        
        $cmd = PHP_BINARY . ' -l ' . $configFile . '  && exit';
        exec($cmd, $outputLines, $exitCode);

        if ($exitCode) {
            throw new ConfigException(
                "Config file failed lint test" . PHP_EOL . implode(PHP_EOL, $outputLines)
            );
        }
        
        $nonEmptyOpts = array_filter($options);
        
        unset($nonEmptyOpts['config']);
        
        if ($nonEmptyOpts) {
            throw new ConfigException(
                "Config incompatible with other directives: " . implode(', ', array_keys($nonEmptyOpts))
            );
        }
        
        $configFile = $options['config'];
        
        if (!@include $configFile) {
            throw new ConfigException(
                "Config file inclusion failure: {$configFile}"
            );
        }
        
        if (!(isset($config) && is_array($config))) {
            throw new ConfigException(
                'Config file must specify a $config array'
            );
        }
        
        return $config;
    }

    private function generateDocRootConfig(array $options) {
        if (empty($options['bind'])) {
            throw new ConfigException(
                'Bind address required (e.g. --bind=*:80 or -b"127.0.0.1:80")'
            );
        }
        
        $bind = $options['bind'];
        
        if ($bind[0] === '*') {
            $bind = str_replace('*', '0.0.0.0', $bind);
        } elseif (!filter_var($bind, FILTER_VALIDATE_IP)) {
            throw new ConfigException(
                "Invalid bind address: {$bind}"
            );
        }
        
        if (empty($options['docroot'])) {
            throw new ConfigException(
                'Document root directive required (e.g. -d="/path/to/files", --docroot)'
            );
        }
        
        $docroot = realpath($options['docroot']);
        
        if (!(is_dir($docroot) && is_readable($docroot))) {
            throw new ConfigException(
                'Document root directive must specify a readable directory path'
            );
        }
        
        $configArr = [
            'listenOn' => $options['bind'],
            'application' => new DocRootLauncher([
                'docRoot' => $docroot
            ])
        ];
        
        if ($options['name']) {
            $configArr['name'] = $options['name'];
        }
        
        return [$configArr];
    }
    
    /**
     * Generate a runnable server from an associative array of configuration settings
     * 
     * @param array $config A formatted server configuration array
     * @throws \Aerys\Config\ConfigException
     * @return \Aerys\Server A ready-to-run server instance
     */
    function createServer(array $config) {
        $reservedKeys = $this->extractReservedKeys($config);
        list($config, $serverOptions, $definitions, $beforeStartCallbacks) = $reservedKeys;
        
        $this->injector = $this->injectorBuilder->fromArray($definitions);
        
        $this->makeEventReactor();
        
        $server = $this->injector->make(self::SERVER_CLASS);
        $this->injector->share($server);
        
        foreach ($this->generateHostDefinitions($config) as $host) {
            $server->registerHost($host);
        }
        
        foreach ($serverOptions as $key => $value) {
            $server->setOption($key, $value);
        }
        
        foreach ($beforeStartCallbacks as $key => $callback) {
            if (is_callable($callback)) {
                $callback($server);
            } else {
                throw new ConfigException(
                    "Invalid aerys.beforeStart callback at key {$key}"
                );
            }
        }
        
        return $server;
    }
    
    private function extractReservedKeys(array $config) {
        $serverOptions = isset($config['aerys.options']) ? $config['aerys.options'] : [];
        unset($config['aerys.options']);
        
        $definitions = isset($config['aerys.definitions']) ? $config['aerys.definitions'] : [];
        unset($config['aerys.definitions']);
        
        $beforeStartCallbacks = isset($config['aerys.beforeStart']) ? $config['aerys.beforeStart'] : [];
        unset($config['aerys.beforeStart']);
        
        return [$config, $serverOptions, $definitions, $beforeStartCallbacks];
    }
    
    private function makeEventReactor() {
        try {
            $reactor = $this->injector->make('Alert\Reactor');
        } catch (InjectionException $e) {
            $this->injector->delegate('Alert\Reactor', ['Alert\ReactorFactory', 'select']);
            $reactor = $this->injector->make('Alert\Reactor');
        }
        
        $this->injector->alias('Alert\Reactor', get_class($reactor));
        $this->injector->share($reactor);
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
