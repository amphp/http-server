<?php

namespace Aerys\Framework;

use Auryn\Injector;

class ResponderBuilder {

    private $injector;
    private $responderOrder = [
        'websockets',
        'routes',
        'user',
        'docroot',
        'reverseproxy'
    ];

    /**
     * @param \Auryn\Injector A dependency injection container
     */
    function __construct(Injector $injector) {
        $this->injector = $injector;
    }

    /**
     * Use a dependency injection container to provision a callable host responder
     *
     * @param array $definition An array specifying responder definitions
     * @return mixed Returns a callable host responder
     */
    function buildResponder(array $definition) {
        $responders = [];

        $responders['websockets'] = ($websocketEndpoints = $definition['websockets'])
            ? $this->generateWebsocketBroker($websocketEndpoints)
            : NULL;

        $responders['routes'] = ($dynamicRoutes = $definition['routes'])
            ? $this->generateRouter($dynamicRoutes)
            : NULL;

        $responders['user'] = ($userResponders = $definition['userResponders'])
            ? $this->generateUserResponders($userResponders)
            : NULL;

        $responders['docroot'] = ($docRootOptions = $definition['documentRoot'])
            ? $this->generateStaticDocRoot($docRootOptions)
            : NULL;

        $responders['reverseproxy'] = ($reverseProxy = $definition['reverseProxy'])
            ? $this->generateProxyProxy($reverseProxy)
            : NULL;

        $responders = $this->orderResponders($responders, $definition['responderOrder']);

        $responders = array_filter($responders);

        switch (count($responders)) {
            case 0:
                throw new ConfigException(
                    'App definition must specify at least one dynamic route, websocket endpoint, ' .
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

    private function generateWebsocketBroker(array $endpoints) {
        $responder = $this->injector->make('Aerys\Responders\Websocket\Broker');
        $this->injector->share($responder);

        foreach ($endpoints as $endpointArr) {
            list($uriPath, $websocketEndpointClass, $endpointOptions) = $endpointArr;
            $endpoint = $this->generateWebsocketBrokerEndpoint($websocketEndpointClass);
            $responder->registerEndpoint($uriPath, $endpoint, $endpointOptions);
        }

        $this->injector->unshare($responder);

        return $responder;
    }

    private function generateWebsocketBrokerEndpoint($endpointClass) {
        try {
            return $this->injector->make($endpointClass);
        } catch (\Exception $previousException) {
            throw new ConfigException(
                "Broker endpoint build failure ({$endpointClass}): " . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function generateRouter(array $dynamicRoutes) {
        $responder = $this->injector->make('Aerys\Responders\Routes\Router');

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
                'Callable route handler build failure: ' . $previousException->getMessage(),
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

        if (count($responders) === 1) {
            $responder = current($responders);
        } else {
            $responder = $this->injector->make('Aerys\Responders\CompositeResponder', [
                ':responders' => $responders
            ]);
        }

        return $responder;
    }

    private function generateExecutableUserResponder($responder) {
        try {
            return $this->injector->getExecutable($responder);
        } catch (\Exception $previousException) {
            throw new ConfigException(
                'Callable user responder build failure: ' . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function generateStaticDocRoot(array $docRootOptions) {
        try {
            $handler = $this->injector->make('Aerys\Responders\Documents\DocRoot');
            $handler->setAllOptions($docRootOptions);

            return $handler;

        } catch (\Exception $previousException) {
            throw new ConfigException(
                'DocRoot build failure: ' . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function generateProxyProxy(array $reverseProxy) {
        try {
            $handler = $this->injector->make('Aerys\Responders\Reverse\Proxy');

            foreach ($reverseProxy['backends'] as $uri) {
                $handler->addBackend($uri);
            }

            unset($reverseProxy['backends']);

            $handler->setAllOptions($reverseProxy);

            return $handler;

        } catch (\Exception $previousException) {
            throw new ConfigException(
                'Proxy build failure: ' . $previousException->getMessage(),
                $errorCode = 0,
                $previousException
            );
        }
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

}
