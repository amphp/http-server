<?php

namespace Aerys\Test\Responders\Routing;

use Aerys\Responders\Routing\RoutingResponder,
    Aerys\Responders\Routing\Router;

class RoutingResponderTest extends \PHPUnit_Framework_TestCase {

    function testResponderInvokesRouteHandlerOnMatch() {
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test'
        ];

        $handler = function($asgiEnv, $requestId) { return $requestId; };
        $routeResult = [Router::MATCHED, $handler, $uriArgs = []];

        $router = $this->getMock('Aerys\Responders\Routing\Router');
        $router->expects($this->once())
               ->method('matchRoute')
               ->with($asgiEnv['REQUEST_METHOD'], $asgiEnv['REQUEST_URI_PATH'])
               ->will($this->returnValue($routeResult));

        $responder = new RoutingResponder($router);
        $asgiResponse = $responder->__invoke($asgiEnv, $requestId = 42);

        $this->assertEquals(42, $asgiResponse);
    }

    function testResponderReturns500IfHandlerInvocationThrows() {
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test'
        ];

        $handler = function() { throw new \Exception('test'); };
        $routeResult = [Router::MATCHED, $handler, $uriArgs = []];

        $router = $this->getMock('Aerys\Responders\Routing\Router');
        $router->expects($this->once())
               ->method('matchRoute')
               ->with($asgiEnv['REQUEST_METHOD'], $asgiEnv['REQUEST_URI_PATH'])
               ->will($this->returnValue($routeResult));

        $responder = new RoutingResponder($router);
        $asgiResponse = $responder->__invoke($asgiEnv, $requestId = 42);

        $this->assertEquals(500, $asgiResponse[0]);
    }

    function testResponderReturns404IfNoRouteMatches() {
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test'
        ];
        $routeResult = [Router::NOT_FOUND];

        $router = $this->getMock('Aerys\Responders\Routing\Router');
        $router->expects($this->once())
               ->method('matchRoute')
               ->with($asgiEnv['REQUEST_METHOD'], $asgiEnv['REQUEST_URI_PATH'])
               ->will($this->returnValue($routeResult));

        $responder = new RoutingResponder($router);
        $asgiResponse = $responder->__invoke($asgiEnv, $requestId = 42);

        $this->assertEquals(404, $asgiResponse[0]);
    }

    function testResponderReturns405IfMethodNotAllowed() {
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test'
        ];
        $routeResult = [Router::METHOD_NOT_ALLOWED, ['POST', 'PUT']];

        $router = $this->getMock('Aerys\Responders\Routing\Router');
        $router->expects($this->once())
               ->method('matchRoute')
               ->with($asgiEnv['REQUEST_METHOD'], $asgiEnv['REQUEST_URI_PATH'])
               ->will($this->returnValue($routeResult));

        $responder = new RoutingResponder($router);
        $asgiResponse = $responder->__invoke($asgiEnv, $requestId = 42);

        $this->assertEquals(405, $asgiResponse[0]);
        $this->assertEquals('Allow: POST,PUT', $asgiResponse[2][0]);
    }

    function testAddRouteDelegatesToInjectedRouter() {
        $httpMethod = 'GET';
        $route = '/path';
        $handler = function(){};

        $router = $this->getMock('Aerys\Responders\Routing\Router');
        $router->expects($this->once())
               ->method('addRoute')
               ->with($httpMethod, $route, $handler);

        $responder = new RoutingResponder($router);

        $this->assertSame($responder, $responder->addRoute($httpMethod, $route, $handler));
    }

}
