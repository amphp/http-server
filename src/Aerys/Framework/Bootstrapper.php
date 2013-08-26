<?php

namespace Aerys\Framework;

use Alert\Reactor,
    Aerys\Host,
    Aerys\Server,
    Aerys\Responders\CompositeResponder,
    Aerys\Responders\Routing\RoutingResponder,
    Aerys\Responders\DocRoot\DocRootResponder,
    Aerys\Responders\Websocket\WebsocketResponder,
    Auryn\Injector,
    Auryn\Provider;

class Bootstrapper {

    private $injector;
    private $reactor;
    private $server;
    private $shortOpts;
    private $longOpts;
    private $shortOptNameMap;
    private $responderOrder;

    /**
     * @param \Alert\Reactor The event reactor underlying the server
     * @param \Aerys\Server The server we're bootstrapping
     * @param \Auryn\Injector A dependency injection container used to provision user route handlers
     */
    function __construct(Reactor $reactor, Server $server, Injector $injector = NULL) {
        $this->reactor = $reactor;
        $this->server = $server;
        $this->injector = $injector ?: new Provider;
        $this->shortOpts = 'a:p:i:n:d:h';
        $this->longOpts = [
            'app:',
            'port:',
            'ip:',
            'name:',
            'docroot:',
            'help'
        ];
        $this->shortOptNameMap = [
            'a' => 'app',
            'p' => 'port',
            'i' => 'ip',
            'n' => 'name',
            'd' => 'docroot',
            'h' => 'help'
        ];
        $this->responderOrder = [
            'websockets',
            'routes',
            'user',
            'docroot',
            'reverseproxy'
        ];
    }

    /**
     * Load an array of CLI option switches and values
     *
     * @return array Returns an array of CLI getopt() switches and values
     */
    function getCommandLineOptions() {
        return getopt($this->shortOpts, $this->longOpts);
    }

    /**
     * Load server configuration from an array of command line option switches and values
     *
     * @param array $options An array of CLI getopt() switches and values
     * @return bool Returns TRUE if the server can run using the specified config, FALSE otherwise
     */
    function loadCommandLineConfig(array $options) {
        if (isset($options['h']) || isset($options['help'])) {
            $this->displayHelp();
            $canRun = FALSE;
        } else {
            $options = $this->normalizeGetoptKeys($options);
            $canRun = $this->loadConfigFromGetoptArray($options);
        }

        return $canRun;
    }

    private function displayHelp() {
        $helpLines = [
            'Options:',
            '--------',
            '-a, --app        Use an app config file to bootstrap the server',
            '-p, --port       Optional port on which to listen (default: 80)',
            '-i, --ip         Optional IP on which to bind (default: all IPv4 interfaces)',
            '-n, --name       Optional host/domain name',
            '-d, --docroot    The filesystem directory from which to serve static files',
            '-h, --help       Display help screen',
            PHP_EOL,
            'Example Usage:',
            '--------------',
            'aerys -a /path/to/app/config.php',
            'aerys --app /path/to/app/config.php',
            'aerys --name mysite.com --docroot /path/to/document/root',
            'aerys -p 1337 -d /path/to/doc/root',
            PHP_EOL
        ];

        echo PHP_EOL, implode(PHP_EOL, $helpLines);
    }

    private function normalizeGetoptKeys(array $options) {
        $normalizedOptions = [
            'app' => NULL,
            'port' => NULL,
            'ip' => NULL,
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

    private function loadConfigFromGetoptArray(array $options) {
        try {
            if ($options['app']) {
                $this->loadFileConfig($options['app']);
            } else {
                $this->loadStaticAppFromGetoptArray($options);
            }

            $canRun = TRUE;

        } catch (ConfigException $e) {
            echo $e->getMessage(), PHP_EOL;
            $canRun = FALSE;
        }

        return $canRun;
    }

    /**
     * Load app and server configuration information from the specified file
     *
     * Configuration files must contain one or more app definitions (Aerys\Framework\App) and may
     * optionally contain one or zero Aerys\Framework\ServerOption instances.
     *
     * @param string $filePath
     * @throws \Aerys\Framework\ConfigException
     * @return void
     */
    function loadFileConfig($filePath) {
        $this->validateConfigFileLint($filePath);
        
        if (!include($filePath)) {
            throw new ConfigException(
                "Config file inclusion failed: {$filePath}"
            );
        }

        $vars = get_defined_vars();

        $apps = [];
        $optionsCount = 0;
        $injectorCount = 0;
        foreach ($vars as $key => $value) {
            if ($value instanceof App) {
                $apps[] = $value;
            } elseif ($value instanceof ServerOptions) {
                $optionsCount++;
                $opts = $value->getAllOptions();
                $this->server->setAllOptions($opts);
            } elseif ($value instanceof Injector) {
                $injectorCount++;
                $this->injector = $value;
            }
        }

        if (!$apps) {
            throw new ConfigException(
                "No Aerys\Framework\App configuration objects found in {$filePath}"
            );
        } elseif ($optionsCount > 1) {
            throw new ConfigException(
                "Only one Aerys\Framework\ServerOptions instance allowed in {$filePath}"
            );
        } elseif ($injectorCount > 1) {
            throw new ConfigException(
                "Only one Auryn\Injector instance allowed in {$filePath}"
            );
        }

        $this->seedInjector();

        foreach ($apps as $app) {
            $this->addApp($app);
        }
    }

    private function validateConfigFileLint($filePath) {
        $cmd = PHP_BINARY . ' -l ' . $filePath . '  && exit';
        exec($cmd, $outputLines, $exitCode);

        if ($exitCode) {
            throw new ConfigException(
                "Config file failed lint test" . PHP_EOL . implode(PHP_EOL, $outputLines)
            );
        }
    }

    private function seedInjector() {
        $this->injector->alias('Alert\Reactor', get_class($this->reactor));
        $this->injector->share($this->reactor);
        $this->injector->share($this->server);
    }

    private function loadStaticAppFromGetoptArray(array $options) {
        $docroot = realpath($options['docroot']);

        if (!($docroot && is_dir($docroot) && is_readable($docroot))) {
            throw new ConfigException(
                'Invalid docroot path: ' . $options['docroot']
            );
        }

        $app = (new App)->setDocumentRoot($docroot);

        if ($port = $options['port']) {
            $app->setPort($port);
        }
        if ($ip = $options['ip']) {
            $app->setAddress($ip);
        }
        if ($name = $options['name']) {
            $app->setName($name);
        }

        $this->seedInjector();

        $this->addApp($app);
    }

    /**
     * Add a server application from the specified definition
     *
     * @param \Aerys\Framework\App An App definition
     * @throws \Aerys\Framework\ConfigException On invalid definition or handler build failure
     * @return \Aerys\Framework\Bootstrapper Returns the current object instance
     */
    function addApp(App $app) {
        $definitionArr = $app->toArray();
        $responder = $this->generateResponder($definitionArr);
        $host = $this->buildHost(
            $definitionArr['port'],
            $definitionArr['address'],
            $definitionArr['name'],
            $responder
        );

        if ($tlsDefinition = $definitionArr['encryption']) {
            $this->setHostEncryption($host, $tlsDefinition);
        }

        $this->server->registerHost($host);

        return $this;
    }

    private function generateResponder(array $definitionArr) {
        $responders = [];

        $responders['websockets'] = ($websocketEndpoints = $definitionArr['websockets'])
            ? $this->generateWebsocketResponder($websocketEndpoints)
            : NULL;

        $responders['routes'] = ($dynamicRoutes = $definitionArr['routes'])
            ? $this->generateRoutingResponder($dynamicRoutes)
            : NULL;

        $responders['user'] = ($userResponders = $definitionArr['userResponders'])
            ? $this->generateUserResponders($userResponders)
            : NULL;

        $responders['docroot'] = ($docRootOptions = $definitionArr['documentRoot'])
            ? $this->generateDocRootResponder($docRootOptions)
            : NULL;

        $responders['reverseproxy'] = ($reverseProxy = $definitionArr['reverseProxy'])
            ? $this->generateReverseProxyResponder($reverseProxy)
            : NULL;

        $responders = $this->orderResponders($responders, $definitionArr['responderOrder']);

        $responders = array_filter($responders);

        switch (count($responders)) {
            case 0:
                throw new ConfigException(
                    'AppDefinition must specify at least one dynamic route, websocket endpoint, ' .
                    'document root or reverse proxy backend'
                );
            case 1:
                $responder = current($responders);
                break;
            default:
                $responder = $this->injector->make('Aerys\Responders\CompositeResponder', [
                    ':responders' => $responders
                ]);
                break;
        }

        return $responder;
    }

    private function orderResponders(array $responders, $userDefinedOrder) {
        $order = array_map('strtolower', $userDefinedOrder);
        
        if ($diff = array_diff($order, $this->responderOrder)) {
            throw new ConfigException(
                'Invalid responder order values: ' . implode(', ', $diff)
            );
        }
        
        foreach ($this->responderOrder as $orderKey) {
            if (!in_array($orderKey, $order)) {
                $order[] = $orderKey;
            }
        }
        
        $orderedResponders = [];
        foreach ($order as $orderKey) {
            $orderedResponders[] = $responders[$orderKey];
        }
        
        return $orderedResponders;
    }

    private function generateWebsocketResponder(array $endpoints) {
        $responder = $this->injector->make('Aerys\Responders\Websocket\WebsocketResponder');
        $this->injector->share($responder);

        foreach ($endpoints as $endpointArr) {
            list($uriPath, $websocketEndpointClass, $endpointOptions) = $endpointArr;
            $endpoint = $this->generateWebsocketEndpoint($websocketEndpointClass);
            $responder->registerEndpoint($uriPath, $endpoint, $endpointOptions);
        }

        $this->injector->unshare($responder);

        return $responder;
    }

    private function generateWebsocketEndpoint($endpointClass) {
        try {
            return $this->injector->make($endpointClass);
        } catch (\Exception $previousException) {
            throw new ConfigException(
                "Failed building websocket endpoint class {$endpointClass}: " . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function generateRoutingResponder(array $dynamicRoutes) {
        $responder = new RoutingResponder;

        foreach ($dynamicRoutes as $routeArr) {
            list($httpMethod, $uriPath, $routeHandler) = $routeArr;

            if (!($routeHandler instanceof \Closure
                || (is_string($routeHandler) && function_exists($routeHandler))
            )) {
                $routeHandler = $this->generateExecutableRouteHandler($routeHandler);
            }

            $responder->addRoute($httpMethod, $uriPath, $routeHandler);
        }

        return $responder;
    }

    private function generateExecutableRouteHandler($handler) {
        try {
            return $this->injector->getExecutable($handler);
        } catch (\Exception $previousException) {
            throw new ConfigException(
                'Failed building callable route handler: ' . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function generateUserResponders(array $userResponders) {
        $responders = [];
        foreach ($userResponders as $responder) {
            if (!($responder instanceof \Closure || (is_string($responder) && function_exists($responder)))) {
                $responder = $this->generateExecutableUserResponder($responder);
            }
            
            $responders[] = $responder;
        }
        
        return (count($responders) === 1)
            ? current($responders)
            : new CompositeResponder($responders);
    }
    
    private function generateExecutableUserResponder($responder) {
        try {
            return $this->injector->getExecutable($responder);
        } catch (\Exception $previousException) {
            throw new ConfigException(
                'Failed building callable user responder: ' . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function generateDocRootResponder(array $docRootOptions) {
        try {
            $handler = $this->injector->make('Aerys\Responders\DocRoot\DocRootResponder');
            $handler->setAllOptions($docRootOptions);

            return $handler;

        } catch (\Exception $previousException) {
            throw new ConfigException(
                'DocRootResponder build failure: ' . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function generateReverseProxyResponder(array $reverseProxy) {
        try {
            $handler = $this->injector->make('Aerys\Responders\ReverseProxy\ReverseProxyResponder');

            foreach ($reverseProxy['backends'] as $uri) {
                $handler->addBackend($uri);
            }

            unset($reverseProxy['backends']);

            $handler->setAllOptions($reverseProxy);

            return $handler;

        } catch (\Exception $previousException) {
            throw new ConfigException(
                'ReverseProxyResponder build failure: ' . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function buildHost($port, $ip, $name, callable $handler) {
        try {
            $name = $name ?: $ip;
            return new Host($ip, $port, $name, $handler);
        } catch (\Exception $previousException) {
            throw new ConfigException(
                'Host build failure: ' . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function setHostEncryption(Host $host, array $tlsDefinition) {
        try {
            $host->setEncryptionContext($tlsDefinition);
        } catch (\Exception $previousException) {
            throw new ConfigException(
                'Host build failure: ' . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

}
