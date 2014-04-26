<?php

namespace Aerys;

use Alert\Reactor,
    Alert\ReactorFactory,
    FastRoute\RouteCollector,
    Auryn\Injector,
    Auryn\Provider,
    Aerys\Aggregate\Responder as AggregateResponder;

class Bootstrapper {
    const OPT_VAR_PREFIX = '__';

    private $injector;
    private $serverOptMap = [
        '__maxconnections'      => Server::OP_MAX_CONNECTIONS,
        '__maxrequests'         => Server::OP_MAX_REQUESTS,
        '__keepalivetimeout'    => Server::OP_KEEP_ALIVE_TIMEOUT,
        '__disablekeepalive'    => Server::OP_DISABLE_KEEP_ALIVE,
        '__maxheaderbytes'      => Server::OP_MAX_HEADER_BYTES,
        '__maxbodybytes'        => Server::OP_MAX_BODY_BYTES,
        '__defaultcontenttype'  => Server::OP_DEFAULT_CONTENT_TYPE,
        '__defaulttextcharset'  => Server::OP_DEFAULT_TEXT_CHARSET,
        '__autoreasonphrase'    => Server::OP_AUTO_REASON_PHRASE,
        '__sendservertoken'     => Server::OP_SEND_SERVER_TOKEN,
        '__normalizemethodcase' => Server::OP_NORMALIZE_METHOD_CASE,
        '__requirebodylength'   => Server::OP_REQUIRE_BODY_LENGTH,
        '__socketsolingersero'  => Server::OP_SOCKET_SO_LINGER_ZERO,
        '__socketbacklogsize'   => Server::OP_SOCKET_BACKLOG_SIZE,
        '__allowedmethods'      => Server::OP_ALLOWED_METHODS,
        '__defaulthost'         => Server::OP_DEFAULT_HOST,
    ];

    private static $ILLEGAL_CONFIG_VAR = 'Illegal config variable; "%s" is a reserved name';

    /**
     * Bootstrap a server and its host definitions from command line arguments
     *
     * @param string $config The application config file path
     * @param array $options
     * @throws \Aerys\Framework\BootException
     * @return array Returns three-element array of the form [$reactor, $server, $hosts]
     */
    public function boot($config, array $opts = []) {
        $bindOpt = isset($opts['bind']) ? (bool) $opts['bind'] : TRUE;
        $socksOpt = isset($opts['socks']) ? (array) $opts['socks'] : [];
        $debugOpt = isset($opts['debug']) ? (bool) $opts['debug'] : FALSE;

        list($reactor, $injector, $apps, $serverOpts) = $this->parseAppConfig($config);

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
        foreach ($apps as $app) {
            $host = $this->buildHost($app);
            $hosts->addHost($host);
        }
        $this->injector = NULL;

        foreach ($serverOpts as $key => $value) {
            $server->setOption($key, $value);
        }

        if ($bindOpt) {
            $server->start($hosts, $socksOpt);
        }

        return [$reactor, $server, $hosts];
    }

    private function parseAppConfig($__config) {
        if (!include($__config)) {
            throw new BootException(
                sprintf("Failed including config file: %s", $__config)
            );
        }

        if (!(isset($__config) && $__config === func_get_args()[0])) {
            throw new BootException(
                sprintf(self::$ILLEGAL_CONFIG_VAR, "__config")
            );
        }

        if (isset($__vars)) {
            throw new BootException(
                sprintf(self::$ILLEGAL_CONFIG_VAR, "__vars")
            );
        }

        $__vars = get_defined_vars();

        foreach (['__apps', '__reactors', '__injectors', '__options'] as $reserved) {
            if (isset($__vars[$reserved])) {
                throw new BootException(
                    sprintf(self::$ILLEGAL_CONFIG_VAR, $reserved)
                );
            }
        }

        $__apps = $__reactors = $__injectors = $__options = [];

        foreach ($__vars as $key => $value) {
            $lowKey = strtolower($key);
            if ($value instanceof App) {
                $__apps[] = $value;
            } elseif ($value instanceof Injector) {
                $__injectors[] = $value;
            } elseif ($value instanceof Reactor) {
                $__reactors[] = $value;
            } elseif (isset($this->serverOptMap[$lowKey])) {
                $__options[$this->serverOptMap[$lowKey]] = $value;
            }
        }

        if (empty($__apps)) {
            throw new BootException(
                sprintf("No app configuration instances found in config file: %s", $__config)
            );
        }

        $reactor = $__reactors ? end($__reactors) : (new ReactorFactory)->select();
        $injector = $__injectors ? end($__injectors) : new Provider;

        return [$reactor, $injector, $__apps, $__options];
    }

    private function buildHost(App $app) {
        $appArr = $app->toArray();
        $ip   = $appArr[App::ADDRESS];
        $port = $appArr[App::PORT];
        $name = $appArr[App::NAME] ?: $ip;
        $tls  = $appArr[App::ENCRYPTION];
        $responder = $this->aggregateAppResponders($appArr);
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

    private function aggregateAppResponders(array $appArr) {
        $responders = [];

        if ($appArr[App::ROUTES] || $appArr[App::THREAD_ROUTES] || $appArr[App::WEBSOCKETS]) {
            $standard = $appArr[App::ROUTES];
            $threaded = $appArr[App::THREAD_ROUTES];
            $websockets = $appArr[App::WEBSOCKETS];
            $responders[App::ROUTES] = $this->buildRoutablesResponder($standard, $threaded, $websockets);
        }

        if ($conf = $appArr[App::RESPONDERS]) {
            $responders[App::RESPONDERS] = $this->buildUserResponder($conf);
        }

        if ($conf = $appArr[App::DOCUMENTS]) {
            $responders[App::DOCUMENTS] = $this->buildDocumentResponder($conf);
        }

        $responders = $this->orderResponders($responders, $appArr[App::ORDER]);

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

    private function buildRoutablesResponder(array $standard, array $threaded, array $websockets) {
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
        $responder = $this->injector->make('Aerys\Routables\Responder', [
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

    private function buildDocumentResponder(array $documentSettings) {
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
                'Documents build failure: ' . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function orderResponders(array $responders, $order) {
        $defaultOrder = [App::WEBSOCKETS, App::ROUTES, App::RESPONDERS, App::DOCUMENTS];
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
