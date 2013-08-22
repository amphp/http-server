<?php

namespace Aerys\Framework;

use Auryn\Injector,
    Auryn\InjectionException,
    Auryn\Provider,
    Aerys\Host,
    Aerys\Responders\CompositeResponder,
    Aerys\Responders\Routing\RoutingResponder,
    Aerys\Responders\DocRoot\DocRootResponder,
    Aerys\Responders\Websocket\WebsocketResponder;

class Bootstrapper {

    private $injector;
    private $reactor;
    private $server;

    /**
     * @param \Auryn\Injector Optional injection container seeded with dependency definitions
     */
    function __construct(Injector $injector = NULL) {
        $this->injector = $injector ?: new Provider;
        $this->reactor = $this->makeEventReactor();
        $this->server = $this->makeServer();
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

        return $reactor;
    }

    private function makeServer() {
        $this->injector->share('Aerys\Server');

        return $this->injector->make('Aerys\Server');
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

    /**
     * Set a server option
     * 
     * @param string $option The option key (case-INsensitve)
     * @param mixed $value The option value to assign
     * @throws \DomainException On unrecognized option key
     * @return \Aerys\Framework\Bootstrapper Returns the current object instance
     */
    function setServerOption($option, $value) {
        $this->server->setOption($option, $value);

        return $this;
    }

    /**
     * Set multiple server options at one time
     * 
     * @param array $options
     * @throws \DomainException On unrecognized option key
     * @return \Aerys\Framework\Bootstrapper Returns the current object instance
     */
    function setAllServerOptions(array $options) {
        $this->server->setAllOptions($options);

        return $this;
    }

    /**
     * Run the bootstrapped server
     * 
     * @throws \Aerys\Framework\ConfigException If no App definitions registered
     * @return void
     */
    function run() {
        try {
            $this->server->start();
            $this->reactor->run();
        } catch (\LogicException $previousException) {
            throw new ConfigException(
                'Cannot start server: no App definitions registered',
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function generateResponder(array $definitionArr) {
        $responders = [];
        
        $responders[] = ($websocketEndpoints = $definitionArr['websockets'])
            ? $this->generateWebsocketResponder($websocketEndpoints)
            : NULL;
        
        $responders[] = ($dynamicRoutes = $definitionArr['routes'])
            ? $this->generateRoutingResponder($dynamicRoutes)
            : NULL;

        $responders[] = ($docRootOptions = $definitionArr['documentRoot'])
            ? $this->generateDocRootResponder($docRootOptions)
            : NULL;

        $responders[] = ($reverseProxy = $definitionArr['reverseProxy'])
            ? $this->generateReverseProxyResponder($reverseProxy)
            : NULL;

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
                "Failed building websocket endpoint class: {$endpointClass}",
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

    private function generateExecutableRouteHandler($routeHandler) {
        try {
            return $this->injector->getExecutable($routeHandler);
        } catch (\Exception $previousException) {
            throw new ConfigException(
                'Failed building callable route handler',
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
                'DocRootResponder build failure',
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
                'ReverseProxyResponder build failure',
                $errorCode = 0,
                $previousException
            );
        }
    }

    private function buildHost($port, $ip, $name, callable $handler) {
        try {
            return new Host($ip, $port, $name, $handler);
        } catch (\Exception $previousException) {
            throw new ConfigException(
                'Host build failure',
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
                'Host build failure',
                $errorCode = 0,
                $previousException
            );
        }
    }

}
