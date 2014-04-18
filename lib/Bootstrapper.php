<?php

namespace Aerys;

use Alert\Reactor,
    Alert\ReactorFactory,
    Auryn\Injector,
    Auryn\Provider,
    Aerys\Aggregate\Responder as AggregateResponder;

class Bootstrapper {
    const OPT_VAR_PREFIX = '__';
    private static $ILLEGAL_CONFIG_VAR = 'Illegal config variable; "%s" is a reserved name';
    private $injector;

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

        $injector->alias('Alert\Reactor', get_class($reactor));
        $injector->share($reactor);
        $injector->share('Aerys\Server');
        $injector->define('Aerys\ResponderBuilder', [':injector' => $injector]);
        $server = $injector->make('Aerys\Server', [':debug' => $debugOpt]);

        // Automatically register any ServerObserver implementations with the server
        $injector->prepare('Aerys\ServerObserver', function($observer) use ($server) {
            $server->addObserver($observer);
        });

        $this->injector = $injector;
        $hosts = new HostCollection;
        foreach ($apps as $app) {
            $host = $this->buildHost($app);
            $hosts->addHost($host);
        }
        $this->injector = NULL;

        $allowedOptions = array_map('strtolower', array_keys($server->getAllOptions()));
        foreach ($serverOpts as $key => $value) {
            if (in_array(strtolower($key), $allowedOptions)) {
                $server->setOption($key, $value);
            }
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
            if ($value instanceof App) {
                $__apps[] = $value;
            } elseif ($value instanceof Injector) {
                $__injectors[] = $value;
            } elseif ($value instanceof Reactor) {
                $__reactors[] = $value;
            } elseif (substr($key, 0, 2) === self::OPT_VAR_PREFIX) {
                $key = substr($key, 2);
                $__options[$key] = $value;
            }
        }

        if (empty($__apps)) {
            throw new BootException(
                "No app configuration instances found in config file"
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

        if ($conf = $appArr[App::WEBSOCKETS]) {
            $responders[App::WEBSOCKETS] = $this->buildWebsocketResponder($conf);
        }

        if ($conf = $appArr[App::ROUTES]) {
            $responders[App::ROUTES] = $this->buildRoutablesResponder($conf);
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

    private function buildWebsocketResponder(array $endpointApps) {
        $wsResponder = $this->injector->make('Aerys\Websocket\Responder');

        foreach ($endpointApps as $endpointStruct) {
            list($wsEndpointUriPath, $wsAppClass, $wsEndpointOptions) = $endpointStruct;
            $wsEndpoint = $this->makeWebsocketEndpoint($wsAppClass);
            $this->configureWebsocketEndpoint($wsEndpoint, $wsEndpointOptions);
            $wsResponder->setEndpoint($wsEndpointUriPath, $wsEndpoint);
        }

        return $wsResponder;
    }

    private function makeWebsocketEndpoint($wsAppClass) {
        try {
            return $this->injector->make('Aerys\Websocket\Endpoint', ['app' => $wsAppClass]);
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

    private function buildRoutablesResponder(array $routes) {
        $dispatcher = \FastRoute\simpleDispatcher(function(\FastRoute\RouteCollector $r) use ($routes) {
            foreach ($routes as $routeArr) {
                list($httpMethod, $uriPath, $routeHandler) = $routeArr;
                if (!($routeHandler instanceof \Closure
                    || (is_string($routeHandler) && function_exists($routeHandler))
                )) {
                    $routeHandler = $this->buildExecutableRouteHandler($routeHandler);
                }

                $r->addRoute($httpMethod, $uriPath, $routeHandler);
            }

        });

        $responder = $this->injector->make('Aerys\Routables\Responder', [
            ':dispatcher' => $dispatcher
        ]);

        return $responder;
    }

    private function buildExecutableRouteHandler($routeHandler) {
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
            $responder = $this->injector->make('Aerys\Documents\Responder', [
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
