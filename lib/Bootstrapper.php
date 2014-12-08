<?php

namespace Aerys;

use Amp\Reactor;
use Amp\Promise;
use Auryn\Injector;
use Auryn\Provider;
use Aerys\Root\Root;
use FastRoute\RouteCollector;

class Bootstrapper {
    const E_CONFIG_INCLUDE = "Config file inclusion failure: %s";
    const E_CONFIG_VARNAME = "Illegal variable name; \"%s\" must not be used in config files";
    const E_MISSING_CONFIG = "No Host instances found in config file: %s";

    private $reactor;
    private $injector;
    private $serverOptMap = [
        'MAX_CONNECTIONS'       => Server::OP_MAX_CONNECTIONS,
        'MAX_REQUESTS'          => Server::OP_MAX_REQUESTS,
        'KEEP_ALIVE_TIMEOUT'    => Server::OP_KEEP_ALIVE_TIMEOUT,
        'DISABLE_KEEP_ALIVE'    => Server::OP_DISABLE_KEEP_ALIVE,
        'MAX_HEADER_BYTES'      => Server::OP_MAX_HEADER_BYTES,
        'MAX_BODY_BYTES'        => Server::OP_MAX_BODY_BYTES,
        'DEFAULT_CONTENT_TYPE'  => Server::OP_DEFAULT_CONTENT_TYPE,
        'DEFAULT_TEXT_CHARSET'  => Server::OP_DEFAULT_TEXT_CHARSET,
        'SEND_SERVER_TOKEN'     => Server::OP_SEND_SERVER_TOKEN,
        'NORMALIZE_METHOD_CASE' => Server::OP_NORMALIZE_METHOD_CASE,
        'REQUIRE_BODY_LENGTH'   => Server::OP_REQUIRE_BODY_LENGTH,
        'SOCKET_SO_LINGER_ZERO' => Server::OP_SOCKET_SO_LINGER_ZERO,
        'SOCKET_BACKLOG_SIZE'   => Server::OP_SOCKET_BACKLOG_SIZE,
        'ALLOWED_METHODS'       => Server::OP_ALLOWED_METHODS,
        'DEFAULT_HOST'          => Server::OP_DEFAULT_HOST,
    ];

    public function __construct(Reactor $reactor = null) {
        $this->reactor = $reactor ?: \Amp\getReactor();
    }

    /**
     * Bootstrap a server and its host definitions from command line arguments
     *
     * @param string $configFile The server config file path
     * @param array $options
     * @throws BootException
     * @return array Returns index array of the form [Server $server, HostGroup $hostGroup]
     */
    public function boot($configFile, array $opts = []) {
        list($hostConfigs, $serverOptions) = $this->parseServerConfigFile($configFile);

        $this->injector = $injector = new Provider;

        $injector->alias('Amp\Reactor', get_class($this->reactor));
        $injector->share($this->reactor);
        $injector->share('Aerys\Server');
        $injector->share('Aerys\AsgiResponderFactory');
        $server = $injector->make('Aerys\Server');

        // Automatically register any ServerObserver implementations with the server
        $injector->prepare('Aerys\ServerObserver', function($observer) use ($server) {
            $server->attachObserver($observer);
        });

        $hostGroup = new HostGroup;
        foreach ($hostConfigs as $hostConfig) {
            $host = $this->buildHost($hostConfig);
            $hostGroup->addHost($host);
        }

        foreach ($serverOptions as $option => $value) {
            $server->setOption($option, $value);
        }

        return [$server, $hostGroup];
    }

    private function parseServerConfigFile($__configFile) {
        if (!include($__configFile)) {
            throw new BootException(
                sprintf(self::E_CONFIG_INCLUDE, $__configFile)
            );
        }

        if (!(isset($__configFile) && $__configFile === func_get_args()[0])) {
            throw new BootException(
                sprintf(self::E_CONFIG_VARNAME, "__configFile")
            );
        }

        if (isset($__configVars)) {
            throw new BootException(
                sprintf(self::E_CONFIG_VARNAME, "__configVars")
            );
        }

        $__configVars = get_defined_vars();
        $this->validateVars($__configVars);

        $__hostConfigs = [];

        foreach ($__configVars as $__cvKey => $__cvValue) {
            if ($__cvValue instanceof Host) {
                $__hostConfigs[] = $__cvValue;
            }
        }

        if (empty($__hostConfigs)) {
            throw new BootException(
                sprintf(self::E_MISSING_CONFIG, $__configFile)
            );
        }

        $__serverOptions = [];
        foreach ($this->serverOptMap as $const => $serverConst) {
            $const = "Aerys\\{$const}";
            if (defined($const)) {
                $__serverOptions[$serverConst] = constant($const);
            }
        }

        return [$__hostConfigs, $__serverOptions];
    }

    private function validateVars(array $vars) {
        if (isset($vars['__hostConfigs'])) {
            throw new BootException(
                sprintf(self::E_CONFIG_VARNAME, "__hostConfigs")
            );
        }

        if (isset($vars['__serverOptions'])) {
            throw new BootException(
                sprintf(self::E_CONFIG_VARNAME, "__serverOptions")
            );
        }

        if (isset($vars['__cvKey'])) {
            throw new BootException(
                sprintf(self::E_CONFIG_VARNAME, "__cvKey")
            );
        }

        if (isset($vars['__cvValue'])) {
            throw new BootException(
                sprintf(self::E_CONFIG_VARNAME, "__cvValue")
            );
        }
    }

    private function buildHost(Host $hostConfig) {
        $hostConfigArr = $hostConfig->toArray();
        $ip   = $hostConfigArr[Host::ADDRESS];
        $port = $hostConfigArr[Host::PORT];
        $name = $hostConfigArr[Host::NAME] ?: $ip;
        $tls  = $hostConfigArr[Host::ENCRYPTION];
        $responder = $this->aggregateHostResponders($hostConfigArr);
        $host = $this->tryHost($ip, $port, $name, $responder);

        if ($tls) {
            $this->tryHostEncryption($host, $tls);
        }

        return $host;
    }

    private function tryHost($ip, $port, $name, $responder) {
        try {
            return new HostDefinition($ip, $port, $name, $responder);
        } catch (\Exception $previousException) {
            throw new BootException(
                sprintf('HostDefinition build failure: %s', $previousException->getMessage()),
                $code = 0,
                $previousException
            );
        }
    }

    private function tryHostEncryption(HostDefinition $host, array $tls) {
        try {
            $host->setEncryptionContext($tlsDefinition);
        } catch (\Exception $previousException) {
            throw new BootException(
                sprintf('HostDefinition build failure: %s', $previousException->getMessage()),
                $code = 0,
                $previousException
            );
        }
    }

    private function aggregateHostResponders(array $hostArr) {
        $responders = [];

        if ($hostArr[Host::ROUTES] || $hostArr[Host::WEBSOCKETS]) {
            $standard = $hostArr[Host::ROUTES];
            $websockets = $hostArr[Host::WEBSOCKETS];
            $responders[Host::ROUTES] = $this->buildRouter($standard, $websockets);
        }

        if ($conf = $hostArr[Host::RESPONDERS]) {
            $responders[Host::RESPONDERS] = $this->buildUserResponder($conf);
        }

        if ($conf = $hostArr[Host::ROOT]) {
            list($root, $isPublicRoot) = $this->buildRootResponder($conf);
            if ($isPublicRoot) {
                $responders[Host::ROOT] = $root;
            }
        } else {
            $root = null;
        }

        return empty($responders)
            ? $this->buildDefaultResponder()
            : $this->buildResponderAggregate($responders, $root);
    }

    private function buildDefaultResponder() {
        return function() {
            return "<html><body><h1>It works!</h1></body></html>";
        };
    }

    private function buildResponderAggregate($requestHandlers) {
        return $this->injector->make('Aerys\\AggregateRequestHandler', [
            ':requestHandlers' => $requestHandlers
        ]);
    }

    private function buildRouter(array $standard, array $websockets) {
        $routeBuilder = function(\FastRoute\RouteCollector $rc) use ($standard, $websockets) {
            if ($standard) {
                $this->buildStandardRouteHandlers($rc, $standard);
            }
            if ($websockets) {
                $this->buildWebsocketRouteHandlers($rc, $websockets);
            }
        };
        $routeDispatcher = \FastRoute\simpleDispatcher($routeBuilder);
        $http404 = [
            'status' => Status::NOT_FOUND,
            'header' => 'Content-Type: text/html; charset=utf-8',
            'body'   => '<html><body><h1>404 Not Found</h1></body></html>',
        ];

        return function($request) use ($routeDispatcher, $http404) {
            $httpMethod = $request['REQUEST_METHOD'];
            $uriPath = $request['REQUEST_URI_PATH'];
            $matchArr = $routeDispatcher->dispatch($httpMethod, $uriPath);

            switch ($matchArr[0]) {
                case \FastRoute\Dispatcher::FOUND:
                    $handler = $matchArr[1];
                    $request['URI_ROUTE_ARGS'] = $matchArr[2];
                    return $handler($request);
                case \FastRoute\Dispatcher::NOT_FOUND:
                    return $http404;
                case \FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                    return [
                        'status' => Status::METHOD_NOT_ALLOWED,
                        'header' => 'Allow: ' . implode(',', $matchArr[1]),
                        'body'   => '<html><body><h1>405 Method Not Allowed</h1></body></html>',
                    ];
                default:
                    throw new \UnexpectedValueException(
                        'Unexpected match code returned from route dispatcher'
                    );
            }
        };
    }

    private function buildStandardRouteHandlers(\FastRoute\RouteCollector $rc, array $routes) {
        try {
            foreach ($routes as list($httpMethod, $uriPath, $handler)) {
                if (!($handler instanceof \Closure || @function_exists($handler))) {
                    $handler = $this->buildStandardRouteExecutable($handler);
                }
                $rc->addRoute($httpMethod, $uriPath, $handler);
            }
        } catch (BootException $previousException) {
            throw new BootException(
                sprintf('Failed building callable for route: %s %s', $httpMethod, $uriPath),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function buildStandardRouteExecutable($routeHandler) {
        try {
            return $this->injector->buildExecutable($routeHandler);
        } catch (\Exception $previousException) {
            throw new BootException(
                'Failed building route handler: ' . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function buildWebsocketRouteHandlers(RouteCollector $rc, array $routes) {
        $uris = [];
        $endpoints = [];
        foreach ($routes as list($uri, $websocketClass, $endpointOptions)) {
            $uris[] = $uri;
            $endpoints[$uri] = $endpoint = $this->makeWebsocketEndpoint($websocketClass);
            $this->configureWebsocketEndpoint($endpoint, $endpointOptions);
        }

        $handshaker = $this->injector->make('Aerys\\Websocket\\Handshaker', [
            ':endpoints' => $endpoints
        ]);

        foreach ($uris as $uri) {
            $rc->addRoute('GET', $uri, $handshaker);
        }
    }

    private function makeWebsocketEndpoint($websocketClassOrInstance) {
        try {
            $definitionKey = is_string($websocketClassOrInstance) ? 'websocket' : ':websocket';
            return $this->injector->make('Aerys\\Websocket\\Endpoint',  [
                $definitionKey => $websocketClassOrInstance
            ]);
        } catch (\Exception $e) {
            throw new BootException("Failed building websocket endpoint: {$websocketClass}", $code = 0, $e);
        }
    }

    private function configureWebsocketEndpoint($endpoint, $options) {
        try {
            $endpoint->setAllOptions($options);
        } catch (\Exception $e) {
            throw new BootException("Failed configuring websocket endpoint", $code = 0, $e);
        }
    }

    private function buildUserResponder(array $userResponders) {
        $responders = [];
        $server = $this->injector->make('Aerys\Server');

        foreach ($userResponders as $responder) {
            if (!($responder instanceof \Closure || (is_string($responder) && function_exists($responder)))) {
                $responder = $this->buildExecutableUserResponder($responder);
            }

            if ($responder instanceof ServerObserver) {
                $server->attachObserver($responder);
            } elseif (is_array($responder) && $responder[0] instanceof ServerObserver) {
                $server->attachObserver($responder[0]);
            }

            $responders[] = $responder;
        }

        return (count($responders) === 1)
            ? current($responders)
            : $this->buildResponderAggregate($responders);
    }

    private function buildExecutableUserResponder($responder) {
        try {
            return $this->injector->buildExecutable($responder);
        } catch (\Exception $previousException) {
            throw new BootException(
                'Callable user responder build failure: ' . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function buildRootResponder(array $rootSettings) {
        try {
            $rootSettings = array_change_key_case($rootSettings);
            $rootPath = $rootSettings['root'];
            $isPublicRoot = empty($rootSettings['private']);

            // Use array_key_exists() so we can capture NULL values and allow applications
            // to avoid loading mime types from a file altogether
            $mimeFile = array_key_exists('mimefile', $rootSettings)
                ? $rootSettings['mimefile']
                : __DIR__ . '/../etc/mime';

            $additionalMimeTypes = isset($rootSettings['mimetypes'])
                ? $rootSettings['mimetypes']
                : [];

            $rootSettings['mimetypes'] = $this->loadRootMimeTypes($mimeFile, $additionalMimeTypes);

            // These aren't actual root option keys; they're bootstrap-only values
            // that we use to configure the document root in the Host instance. We
            // remove them because we don't want to get an error when passing the
            // remaining settings to Root::setAllOptions().
            unset(
                $rootSettings['root'],
                $rootSettings['mimefile'],
                $rootSettings['private']
            );

            $reactor = $this->injector->make('Amp\Reactor');
            $rootClass = ($reactor instanceof \Amp\UvReactor)
                ? 'Aerys\\Root\\UvRoot'
                : 'Aerys\\Root\\NaiveRoot';

            $root = $this->injector->make($rootClass, [':rootPath' => $rootPath]);
            $root->setAllOptions($rootSettings);

            return [$root, $isPublicRoot];

        } catch (\Exception $previousException) {
            throw new BootException(
                'Failed building document root handler: ' . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function loadRootMimeTypes($mimeFile, $additionalTypes) {
        // Allow applications to avoid load mime types from a file if the value is NULL
        $mimeTypes = isset($mimeFile) ? $this->loadRootMimeTypesFromFile($mimeFile) : [];
        $additionalTypes = array_change_key_case($additionalTypes);

        // DO NOT use array_merge() here because we don't want numeric
        // file extension keys to be re-indexed.
        foreach ($additionalTypes as $key => $value) {
            $mimeTypes[$key] = $value;
        }

        return $mimeTypes;
    }

    private function loadRootMimeTypesFromFile($mimeFile) {
        $mimeFile = str_replace('\\', '/', $mimeFile);
        $mimeStr = @file_get_contents($mimeFile);
        if ($mimeStr === false) {
            throw new BootException(
                sprintf('Failed loading mime associations from file: %s', $mimeFile)
            );
        }

        if (!preg_match_all("#\s*([a-z0-9]+)\s+(.+)#i", $mimeStr, $matches)) {
            throw new BootException(
                sprintf('No mime associations found in file: %s', $mimeFile)
            );
        }

        $mimeTypes = [];
        foreach ($matches[1] as $key => $value) {
            $mimeTypes[strtolower($value)] = $matches[2][$key];
        }

        return $mimeTypes;
    }
}
