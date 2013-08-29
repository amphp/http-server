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
    function setInjector(Injector $injector) {
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
            ? $this->generateWebsocketResponder($websocketEndpoints)
            : NULL;

        $responders['routes'] = ($dynamicRoutes = $definition['routes'])
            ? $this->generateRoutingResponder($dynamicRoutes)
            : NULL;

        $responders['user'] = ($userResponders = $definition['userResponders'])
            ? $this->generateUserResponders($userResponders)
            : NULL;

        $responders['docroot'] = ($docRootOptions = $definition['documentRoot'])
            ? $this->generateDocRootResponder($docRootOptions)
            : NULL;

        $responders['reverseproxy'] = ($reverseProxy = $definition['reverseProxy'])
            ? $this->generateReverseProxyResponder($reverseProxy)
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
        $responder = $this->injector->make('Aerys\Responders\Routing\RoutingResponder', [
            'router' => 'Aerys\Responders\Routing\CompositeRegexRouter'
        ]);

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
