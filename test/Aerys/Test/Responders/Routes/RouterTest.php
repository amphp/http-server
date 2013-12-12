<?php

namespace Aerys\Test\Responders\Routes;

use Aerys\Responders\Routes\Router,
    Aerys\Responders\Routes\RouteMatcher;

class RouterTest extends \PHPUnit_Framework_TestCase {

    function testResponderInvokesRouteHandlerOnMatch() {
        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test'
        ];

        $handler = function($asgiRequest, $requestId) { return $requestId; };
        $routeResult = [Router::MATCHED, $handler, $uriArgs = []];

        $router = $this->getMock('Aerys\Responders\Routes\Router');
        $router->expects($this->once())
               ->method('matchRoute')
               ->with($asgiRequest['REQUEST_METHOD'], $asgiRequest['REQUEST_URI_PATH'])
               ->will($this->returnValue($routeResult));

        $responder = new Router($router);
        $asgiResponse = $responder->__invoke($asgiRequest, $requestId = 42);

        $this->assertEquals(42, $asgiResponse);
    }

    function testResponderReturns500IfHandlerInvocationThrows() {
        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test'
        ];

        $handler = function() { throw new \Exception('test'); };
        $routeResult = [Router::MATCHED, $handler, $uriArgs = []];

        $router = $this->getMock('Aerys\Responders\Routes\Router');
        $router->expects($this->once())
               ->method('matchRoute')
               ->with($asgiRequest['REQUEST_METHOD'], $asgiRequest['REQUEST_URI_PATH'])
               ->will($this->returnValue($routeResult));

        $responder = new Router($router);
        $asgiResponse = $responder->__invoke($asgiRequest, $requestId = 42);

        $this->assertEquals(500, $asgiResponse[0]);
    }

    function testResponderReturns404IfNoRouteMatches() {
        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test'
        ];
        $routeResult = [Router::NOT_FOUND];

        $router = $this->getMock('Aerys\Responders\Routes\Router');
        $router->expects($this->once())
               ->method('matchRoute')
               ->with($asgiRequest['REQUEST_METHOD'], $asgiRequest['REQUEST_URI_PATH'])
               ->will($this->returnValue($routeResult));

        $responder = new Router($router);
        $asgiResponse = $responder->__invoke($asgiRequest, $requestId = 42);

        $this->assertEquals(404, $asgiResponse[0]);
    }

    function testResponderReturns405IfMethodNotAllowed() {
        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test'
        ];
        $routeResult = [Router::METHOD_NOT_ALLOWED, ['POST', 'PUT']];

        $router = $this->getMock('Aerys\Responders\Routes\Router');
        $router->expects($this->once())
               ->method('matchRoute')
               ->with($asgiRequest['REQUEST_METHOD'], $asgiRequest['REQUEST_URI_PATH'])
               ->will($this->returnValue($routeResult));

        $responder = new Router($router);
        $asgiResponse = $responder->__invoke($asgiRequest, $requestId = 42);

        $this->assertEquals(405, $asgiResponse[0]);
        $this->assertEquals('Allow: POST,PUT', $asgiResponse[2][0]);
    }

    function testAddRouteDelegatesToInjectedRouter() {
        $httpMethod = 'GET';
        $route = '/path';
        $handler = function(){};

        $router = $this->getMock('Aerys\Responders\Routes\Router');
        $router->expects($this->once())
               ->method('addRoute')
               ->with($httpMethod, $route, $handler);

        $responder = new Router($router);

        $this->assertSame($responder, $responder->addRoute($httpMethod, $route, $handler));
    }

}
