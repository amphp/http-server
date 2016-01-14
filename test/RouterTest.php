<?php

namespace Aerys\Test;

use Amp\ {
    Promise,
    Success,
    function wait,
    function resolve
};

use Aerys\{
    Client,
    InternalRequest,
    Middleware,
    Options,
    Router,
    Server,
    Response,
    StandardRequest,
    StandardResponse
};

class RouterTest extends \PHPUnit_Framework_TestCase {
    function mockServer($state) {
        return new class($state) extends Server {
            private $state;
            private $options;
            function __construct($state) { $this->state = $state; $this->options = new Options; }
            function state(): int {
                return $this->state;
            }
            function getOption(string $opt) { return $this->options->$opt; }
            function setOption(string $opt, $val) { return $this->options->$opt = $val; }
        };
    }

    function mockResponse($state = Response::NONE) {
        return new class($state) implements Response {
            private $state;
            public function __construct($state) { $this->state = $state; }
            function setStatus(int $code): Response { return $this; }
            function setReason(string $phrase): Response { return $this; }
            function addHeader(string $field, string $value): Response { return $this; }
            function setHeader(string $field, string $value): Response { return $this; }
            function setCookie(string $field, string $value, array $flags = []): Response { return $this; }
            function send(string $body) { $this->state = self::ENDED; }
            function stream(string $partialBodyChunk): Promise { return new Success; }
            function flush() { }
            function end(string $finalBodyChunk = null) { }
            function push(string $url, array $headers = null): Response { return $this; }
            function state(): int { return 42; }
        };
    }

    /**
     * @expectedException DomainException
     * @expectedExceptionMessage Aerys\Router::route requires a non-empty string HTTP method at Argument 1
     */
    function testRouteThrowsOnEmptyMethodString() {
        $router = new Router;
        $router->route("", "/uri", function(){});
    }

    /**
     * @expectedException DomainException
     * @expectedExceptionMessage Aerys\Router::route requires at least one callable route action or middleware at Argument 3
     */
    function testRouteThrowsOnEmptyActionsArray() {
        $router = new Router;
        $router->route("GET", "/uri");
    }

    function testUpdateFailsIfStartedWithoutAnyRoutes() {
        $router = new Router;
        $mock = $this->mockServer(Server::STARTING);
        $result = $router->update($mock);
        $this->assertInstanceOf("Amp\\Failure", $result);
        $i = 0;
        $result->when(function($e, $r) use (&$i) {
            $i++;
            $this->assertInstanceOf("DomainException", $e);
            $this->assertSame("Router start failure: no routes registered", $e->getMessage());
        });
        $this->assertSame($i, 1);
    }

    function testMultiActionRouteInvokesEachCallableUntilResponseIsStarted() {
        $i = 0;
        $foo = function() use (&$i) { $i++; };
        $bar = function($request, $response) use (&$i) {
            $i++;
            $response->send("test");
        };

        $router = new Router;
        $router->route("GET", "/{name}/{age}", $foo, $bar, $foo);
        $router->prefix("/genius");
        $mock = $this->mockServer(Server::STARTING);
        $router->update($mock);

        $ireq = new InternalRequest;
        $request = new StandardRequest($ireq);
        $ireq->locals = [];
        $ireq->method = "GET";
        $ireq->uriPath = "/genius/daniel/32";
        $response = $this->mockResponse();

        $this->assertFalse($router->do($ireq)->valid());
        $multiAction = $router($request, $response);

        wait(resolve($multiAction));

        $this->assertSame(3, $i);
        $this->assertSame(["name" => "daniel", "age" => "32"], $ireq->locals["aerys.routeArgs"]);
    }

    function testCachedMiddlewareRoute() {
        $middleware = new class implements Middleware {
            function do(InternalRequest $ireq) {
                $data = yield;
                $data = "middleware + " . yield $data;
                while (true) {
                    $data = yield $data;
                }
            }
        };
        $action = function($req, $res) {
            $res->end("action");
        };

        $router = new Router;
        $router->get("/", $middleware, $action);
        $mock = $this->mockServer(Server::STARTING);
        $router->update($mock);

        for ($i = 0; $i < 2; $i++) {
            $received = "";

            $ireq = new InternalRequest;
            $ireq->locals = [];
            $ireq->method = "GET";
            $ireq->uriPath = "/";
            $ireq->responseWriter = (function () use (&$headers, &$received) {
                $headers = yield;
                while (true) {
                    $received .= yield;
                }
            })();

            $request = new StandardRequest($ireq);
            $filter = \Aerys\responseFilter([[$router, "do"]], $ireq);
            $filter->current();
            $response = new StandardResponse(\Aerys\responseCodec($filter, $ireq), new Client);

            $router($request, $response);

            $this->assertEquals("middleware + action", $received);
        }
    }
}
