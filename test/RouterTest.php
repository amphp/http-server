<?php

namespace Aerys\Test;

use Amp\ {
    NativeReactor,
    function wait,
    function resolve
};

use Aerys\{
    Router,
    Server,
    Response,
    Request
};

class RouterTest extends \PHPUnit_Framework_TestCase {
    private function mockServer($state) {
        return new class($state) extends Server {
            private $state;
            function __construct($state) { $this->state = $state; }
            function state(): int {
                return $this->state;
            }
        };
    }

    private function mockResponse($state = Response::NONE) {
        return new class($state) implements Response {
            private $state;
            public function __construct($state) { $this->state = $state; }
            function setStatus(int $code): Response { return $this; }
            function setReason(string $phrase): Response { return $this; }
            function addHeader(string $field, string $value): Response { return $this; }
            function setHeader(string $field, string $value): Response { return $this; }
            function send(string $body): Response { $this->state = self::ENDED; return $this; }
            function stream(string $partialBodyChunk): Response { return $this; }
            function flush(): Response { return $this; }
            function end(string $finalBodyChunk = null): Response { return $this; }
            function state(): int { return 42; }
        };
    }

    /**
     * @expectedException DomainException
     * @expectedExceptionMessage max_cache_entries requires an integer; string specified
     */
    public function testCtorFailsOnBadMaxCacheEntriesOption() {
        $router = new Router(["max_cache_entries" => "42"]);
    }

    /**
     * @expectedException DomainException
     * @expectedExceptionMessage Unknown Router option: zanzibar
     */
    public function testCtorFailsOnUnknownOption() {
        $router = new Router(["zanzibar" => 42]);
    }

    /**
     * @expectedException DomainException
     * @expectedExceptionMessage Aerys\Router::route requires a non-empty string HTTP method at Argument 1
     */
    public function testRouteThrowsOnEmptyMethodString() {
        $router = new Router;
        $router->route("", "/uri", function(){});
    }

    /**
     * @expectedException DomainException
     * @expectedExceptionMessage Aerys\Router::route requires a non-empty string URI at Argument 2
     */
    public function testRouteThrowsOnEmptyUriString() {
        $router = new Router;
        $router->route("GET", "", function(){});
    }

    /**
     * @expectedException DomainException
     * @expectedExceptionMessage Aerys\Router::route requires at least one callable route action at Argument 3
     */
    public function testRouteThrowsOnEmptyActionsArray() {
        $router = new Router;
        $router->route("GET", "/uri");
    }

    public function testUpdateFailsIfStartedWithoutAnyRoutes() {
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

    public function testMultiActionRouteInvokesEachCallableUntilResponseIsStarted() {
        $i = 0;
        $foo = function() use (&$i) { $i++; };
        $bar = function($request, $response) use (&$i) {
            $i++;
            $response->send("test");
        };

        $router = new Router;
        $router->route("GET", "/{name}/{age}", $foo, $bar, $foo);
        $mock = $this->mockServer(Server::STARTING);
        $router->update($mock);

        $request = new Request;
        $request->locals = new \StdClass;
        $request->method = "GET";
        $request->uriPath = "/daniel/32";
        $response = $this->mockResponse();

        $multiAction = $router($request, $response);
        $reactor = new NativeReactor;
        wait(resolve($multiAction, $reactor), $reactor);

        $this->assertSame(2, $i);
        $this->assertSame(["name" => "daniel", "age" => "32"], $request->locals->routeArgs);
    }
}
