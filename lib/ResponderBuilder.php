<?php

namespace Aerys;

use Auryn\Injector;
use Aerys\Composite\Responder as CompositeResponder;

class ResponderBuilder {
    private $injector;

    public function __construct(Injector $injector) {
        $this->injector = $injector;
    }

    /**
     * Provision a callable server host responder
     *
     * @param array $appDefinition An array specifying responder definitions
     * @return callable
     */
    public function buildResponder(array $appDefinition) {
        $responders = [];

        if ($appDefinition[App::WEBSOCKETS]) {
            $responder = $this->buildWsResponder($appDefinition[App::WEBSOCKETS]);
            $responders[App::WEBSOCKETS] = $responder;
        }

        if ($appDefinition[App::ROUTES]) {
            $responder = $this->buildRoutablesResponder($appDefinition[App::ROUTES]);
            $responders[App::ROUTES] = $responder;
        }

        if ($appDefinition[App::RESPONDERS]) {
            $responder = $this->buildUserResponder($appDefinition[App::RESPONDERS]);
            $responders[App::RESPONDERS] = $responder;
        }

        if ($appDefinition[App::DOCUMENTS]) {
            $responder = $this->buildDocumentResponder($appDefinition[App::DOCUMENTS]);
            $responders[App::DOCUMENTS] = $responder;
        }

        $responders = $this->orderResponders($responders, $appDefinition[App::ORDER]);

        switch (count($responders)) {
            case 0:
                $responder = $this->buildDefaultResponder();
            case 1:
                $responder = current($responders);
                break;
            default:
                $responder = new CompositeResponder($responders);
                break;
        }

        return $responder;
    }

    private function buildDefaultResponder() {
        return function() {
            return "<html><body><h1>It works!</h1></body></html>";
        };
    }

    private function buildWsResponder(array $endpointApps) {
        $wsResponder = $this->injector->make('Aerys\Websocket\WsResponder');

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
                    $routeHandler = $this->generateExecutableRouteHandler($routeHandler);
                }

                $r->addRoute($httpMethod, $uriPath, $routeHandler);
            }

        });

        $responder = $this->injector->make('Aerys\Routables\Responder', [
            ':dispatcher' => $dispatcher
        ]);

        return $responder;
    }

    private function generateExecutableRouteHandler($routeHandler) {
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
                $responder = $this->generateExecutableUserResponder($responder);
            }

            if ($responder instanceof ServerObserver) {
                $server->addObserver($responder);
            } elseif (is_array($responder) && $responder[0] instanceof ServerObserver) {
                $server->addObserver($responder[0]);
            }

            $responders[] = $responder;
        }

        return (count($responders) === 1) ? current($responders) : new CompositeResponder($responders);
    }

    private function generateExecutableUserResponder($responder) {
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
            $responder = $this->injector->make('Aerys\Documents\DocsResponder', [
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
