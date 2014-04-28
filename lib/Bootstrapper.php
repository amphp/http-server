<?php

namespace Aerys;

use Alert\Reactor,
    Alert\ReactorFactory,
    FastRoute\RouteCollector,
    Auryn\Injector,
    Auryn\Provider,
    Aerys\Aggregate\Responder as AggregateResponder;

class Bootstrapper {
    const E_CONFIG_INCLUDE = "Config file inclusion failure: %s";
    const E_CONFIG_VARNAME = "Illegal variable name; \"%s\" must not be used in config files";
    const E_MISSING_CONFIG = "No HostConfig instances found in config file: %s";

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
        'AUTO_REASON_PHRASE'    => Server::OP_AUTO_REASON_PHRASE,
        'SEND_SERVER_TOKEN'     => Server::OP_SEND_SERVER_TOKEN,
        'NORMALIZE_METHOD_CASE' => Server::OP_NORMALIZE_METHOD_CASE,
        'REQUIRE_BODY_LENGTH'   => Server::OP_REQUIRE_BODY_LENGTH,
        'SOCKET_SO_LINGER_ZERO' => Server::OP_SOCKET_SO_LINGER_ZERO,
        'SOCKET_BACKLOG_SIZE'   => Server::OP_SOCKET_BACKLOG_SIZE,
        'ALLOWED_METHODS'       => Server::OP_ALLOWED_METHODS,
        'DEFAULT_HOST'          => Server::OP_DEFAULT_HOST,
    ];

    /**
     * Bootstrap a server and its host definitions from command line arguments
     *
     * @param string $config The server config file path
     * @param array $options
     * @throws \Aerys\Framework\BootException
     * @return array Returns three-element array of the form [$reactor, $server, $hosts]
     */
    public function boot($config, array $opts = []) {
        $bindOpt = isset($opts['bind']) ? (bool) $opts['bind'] : TRUE;
        $socksOpt = isset($opts['socks']) ? (array) $opts['socks'] : [];
        $debugOpt = isset($opts['debug']) ? (bool) $opts['debug'] : FALSE;

        list($hostConfigs, $serverOptions) = $this->parseHostConfigs($config);

        $reactor = (new ReactorFactory)->select();
        $injector = new Provider;
        $this->injector = $injector;

        $injector->alias('Alert\Reactor', get_class($reactor));
        $injector->share($reactor);
        $injector->share('Aerys\Server');
        $injector->share('Amp\Dispatcher');
        $server = $injector->make('Aerys\Server', [':debug' => $debugOpt]);

        // Automatically register any ServerObserver implementations with the server
        $injector->prepare('Aerys\ServerObserver', function($observer) use ($server) {
            $server->addObserver($observer);
        });

        $hosts = new HostCollection;
        foreach ($hostConfigs as $hostConfig) {
            $host = $this->buildHost($hostConfig);
            $hosts->addHost($host);
        }
        $this->injector = NULL;

        foreach ($serverOptions as $option => $value) {
            $server->setOption($option, $value);
        }

        if ($bindOpt) {
            $server->start($hosts, $socksOpt);
        }

        return [$reactor, $server, $hosts];
    }

    private function parseHostConfigs($__configFile) {
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
            if ($__cvValue instanceof HostConfig) {
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
            $const = "Aerys\{$const}";
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

    private function buildHost(HostConfig $hostConfig) {
        $hostConfigArr = $hostConfig->toArray();
        $ip   = $hostConfigArr[HostConfig::ADDRESS];
        $port = $hostConfigArr[HostConfig::PORT];
        $name = $hostConfigArr[HostConfig::NAME] ?: $ip;
        $tls  = $hostConfigArr[HostConfig::ENCRYPTION];
        $responder = $this->aggregateHostResponders($hostConfigArr);
        $host = $this->tryHost($ip, $port, $name, $responder);

        if ($tls) {
            $this->tryHostEncryption($host, $tls);
        }

        return $host;
    }

    private function tryHost($ip, $port, $name, $responder) {
        try {
            return new Host($ip, $port, $name, $responder);
        } catch (\Exception $previousException) {
            throw new BootException(
                sprintf('Host build failure: %s', $e->getMessage()),
                $code = 0,
                $previousException
            );
        }
    }

    private function tryHostEncryption(Host $host, array $tls) {
        try {
            $host->setEncryptionContext($tlsDefinition);
        } catch (\Exception $previousException) {
            throw new BootException(
                sprintf('Host build failure: %s', $lastException->getMessage()),
                $code = 0,
                $previousException
            );
        }
    }

    private function aggregateHostResponders(array $hostArr) {
        $responders = [];

        if ($hostArr[HostConfig::ROUTES] ||
            $hostArr[HostConfig::THREAD_ROUTES] ||
            $hostArr[HostConfig::WEBSOCKETS]
        ) {
            $standard = $hostArr[HostConfig::ROUTES];
            $threaded = $hostArr[HostConfig::THREAD_ROUTES];
            $websockets = $hostArr[HostConfig::WEBSOCKETS];
            $responders[HostConfig::ROUTES] = $this->buildRoutableResponder($standard, $threaded, $websockets);
        }

        if ($conf = $hostArr[HostConfig::RESPONDERS]) {
            $responders[HostConfig::RESPONDERS] = $this->buildUserResponder($conf);
        }

        if ($conf = $hostArr[HostConfig::DOCUMENTS]) {
            $responders[HostConfig::DOCUMENTS] = $this->buildDocRootResponder($conf);
        }

        $responders = $this->orderResponders($responders, $hostArr[HostConfig::ORDER]);

        switch (count($responders)) {
            case 0:
                $responder = $this->buildDefaultResponder();
                break;
            case 1:
                $responder = current($responders);
                break;
            default:
                $responder = new AggregateResponder($responders);
                break;
        }

        return $responder;
    }

    private function buildDefaultResponder() {
        return function() {
            return "<html><body><h1>It works!</h1></body></html>";
        };
    }

    private function buildRoutableResponder(array $standard, array $threaded, array $websockets) {
        $routeBuilder = function(RouteCollector $rc) use ($standard, $threaded, $websockets) {
            if ($standard) {
                $this->buildStandardRouteHandlers($rc, $standard);
            }
            if ($threaded) {
                $this->buildThreadedRouteHandlers($rc, $threaded);
            }
            if ($websockets) {
                $this->buildWebsocketRouteHandlers($rc, $websockets);
            }
        };
        $dispatcher = \FastRoute\simpleDispatcher($routeBuilder);
        $responder = $this->injector->make('Aerys\Routable\Responder', [
            ':dispatcher' => $dispatcher
        ]);

        return $responder;
    }

    private function buildStandardRouteHandlers(RouteCollector $rc, array $routes) {
        foreach ($routes as list($httpMethod, $uriPath, $handler)) {
            if (!($handler instanceof \Closure || @function_exists($handler))) {
                $handler = $this->buildStandardRouteExecutable($handler);
            }
            $rc->addRoute($httpMethod, $uriPath, $handler);
        }
    }

    private function buildStandardRouteExecutable($routeHandler) {
        try {
            return $this->injector->getExecutable($routeHandler);
        } catch (\Exception $previousException) {
            throw new BootException(
                'Callable route handler build failure: ' . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function buildThreadedRouteHandlers(RouteCollector $rc, array $routes) {
        $responder = $this->injector->make('Aerys\Blockable\Responder');
        foreach ($routes as list($httpMethod, $uriPath, $handler)) {
            // @TODO Allow Class::method strings once auto injection is added inside
            // worker threads. For now we'll just require function names.
            if (!@function_exists($handler)) {
                throw new BootException(
                    'Thread route handler must be a function or class::method string'
                );
            }

            $rc->addRoute($httpMethod, $uriPath, function($request) use ($responder, $handler) {
                $request['AERYS_THREAD_ROUTE'] = $handler;
                return $responder->__invoke($request);
            });
        }
    }

    private function buildWebsocketRouteHandlers(RouteCollector $rc, array $routes) {
        $handshaker = $this->injector->make('Aerys\Websocket\HandshakeResponder');
        foreach ($routes as list($uriPath, $handlerClass, $endpointOptions)) {
            $endpoint = $this->makeWebsocketEndpoint($handlerClass);
            $this->configureWebsocketEndpoint($endpoint, $endpointOptions);
            $rc->addRoute('GET', $uriPath, function($request) use ($handshaker, $endpoint) {
                return $handshaker->handshake($endpoint, $request);
            });
        }
    }

    private function makeWebsocketEndpoint($handlerClass) {
        try {
            return $this->injector->make('Aerys\Websocket\Endpoint', ['app' => $handlerClass]);
        } catch (\Exception $e) {
            throw new BootException("Failed building websocket endpoint", $code = 0, $e);
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
                $server->addObserver($responder);
            } elseif (is_array($responder) && $responder[0] instanceof ServerObserver) {
                $server->addObserver($responder[0]);
            }

            $responders[] = $responder;
        }

        return (count($responders) === 1)
            ? current($responders)
            : new AggregateResponder($responders);
    }

    private function buildExecutableUserResponder($responder) {
        try {
            return $this->injector->getExecutable($responder);
        } catch (\Exception $previousException) {
            throw new BootException(
                'Callable user responder build failure: ' . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function buildDocRootResponder(array $documentSettings) {
        try {
            $root = $documentSettings['root'];
            unset($documentSettings['root']);
            $responder = $this->injector->make('Aerys\DocRoot\Responder', [
                ':root' => $root
            ]);
            $responder->setAllOptions($documentSettings);

            return $responder;

        } catch (\Exception $previousException) {
            throw new BootException(
                'DocRoot build failure: ' . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function orderResponders(array $responders, $order) {
        $defaultOrder = [HostConfig::WEBSOCKETS, HostConfig::ROUTES, HostConfig::RESPONDERS, HostConfig::DOCUMENTS];
        if ($diff = array_diff($order, $defaultOrder)) {
            throw new BootException(
                'Invalid responder order value(s): ' . implode(', ', $diff)
            );
        }

        foreach ($defaultOrder as $orderKey) {
            if (!in_array($orderKey, $order)) {
                $order[] = $orderKey;
            }
        }

        $orderedResponders = [];
        foreach ($order as $orderKey) {
            if (isset($responders[$orderKey])) {
                $orderedResponders[] = $responders[$orderKey];
            }
        }

        return $orderedResponders;
    }
}
