<?php

namespace Aerys\Test;

use Aerys\Client;
use Aerys\Filter;
use Aerys\InternalRequest;

use Aerys\Options;
use Aerys\Request;
use Aerys\Response;
use Aerys\Router;
use Aerys\Server;
use Aerys\StandardResponse;
use Amp\ Coroutine;
use Amp\ Promise;
use Amp\ Success;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase {
    public function mockServer($state) {
        return new class($state) extends Server {
            private $state;
            private $options;
            public function __construct($state) {
                $this->state = $state;
                $this->options = new Options;
            }
            public function state(): int {
                return $this->state;
            }
            public function getOption(string $opt) {
                return $this->options->$opt;
            }
            public function setOption(string $opt, $val) {
                return $this->options->$opt = $val;
            }
        };
    }

    public function mockResponse($state = Response::NONE) {
        return new class($state) implements Response {
            private $state;
            public $headers = [];
            public $status = 200;
            public function __construct($state) {
                $this->state = $state;
            }
            public function setStatus(int $code): Response {
                $this->status = $code;
                return $this;
            }
            public function setReason(string $phrase): Response {
                return $this;
            }
            public function addHeader(string $field, string $value): Response {
                $this->headers[strtolower($field)] = $value;
                return $this;
            }
            public function setHeader(string $field, string $value): Response {
                $this->headers[strtolower($field)] = $value;
                return $this;
            }
            public function setCookie(string $field, string $value, array $flags = []): Response {
                return $this;
            }
            public function send(string $body) {
                $this->state = self::ENDED;
            }
            public function write(string $partialBodyChunk): Promise {
                return new Success;
            }
            public function flush() {
            }
            public function end(string $finalBodyChunk = ""): Promise {
                return new Success;
            }
            public function abort() {
            }
            public function push(string $url, array $headers = null): Response {
                return $this;
            }
            public function state(): int {
                return $this->state;
            }
        };
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Aerys\Router::route requires a non-empty string HTTP method at Argument 1
     */
    public function testRouteThrowsOnEmptyMethodString() {
        $router = new Router;
        $router->route("", "/uri", function () {});
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Aerys\Router::route requires at least one callable route action or filter at Argument 3
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
        $result->onResolve(function ($e, $r) use (&$i) {
            $i++;
            $this->assertInstanceOf("Error", $e);
            $this->assertSame("Router start failure: no routes registered", $e->getMessage());
        });
        $this->assertSame($i, 1);
    }

    public function testUseCanonicalRedirector() {
        $router = new Router;
        $router->route("GET", "/{name}/{age}/?", function ($req, $res) { $res->send("OK"); });
        $router->prefix("/mediocre-dev");
        $router = (new Router)->use($router);
        $mock = $this->mockServer(Server::STARTING);
        $router->update($mock);

        $ireq = new InternalRequest;
        $request = new Request($ireq);
        $ireq->locals = [];
        $ireq->method = "GET";
        $ireq->uri = $ireq->uriPath = "/mediocre-dev/bob/19/";
        $response = $this->mockResponse();

        $this->assertFalse($router->filter($ireq)->valid());
        $multiAction = $router($request, $response);

        if ($multiAction) {
            Promise\wait(new Coroutine($multiAction));
        }

        $this->assertEquals(\Aerys\HTTP_STATUS["FOUND"], $response->status);
        $this->assertEquals("/mediocre-dev/bob/19", $response->headers["location"]);
        $this->assertSame(["name" => "bob", "age" => "19"], $ireq->locals["aerys.routeArgs"]);

        $ireq = new InternalRequest;
        $request = new Request($ireq);
        $ireq->locals = [];
        $ireq->method = "GET";
        $ireq->uriPath = "/mediocre-dev/bob/19";
        $response = $this->mockResponse();

        $this->assertFalse($router->filter($ireq)->valid());
        $multiAction = $router($request, $response);

        if ($multiAction) {
            Promise\wait(new Coroutine($multiAction));
        }

        $this->assertEquals(\Aerys\Response::ENDED, $response->state());
        $this->assertEquals(\Aerys\HTTP_STATUS["OK"], $response->status);
    }

    public function testMultiActionRouteInvokesEachCallableUntilResponseIsStarted() {
        $i = 0;
        $foo = function () use (&$i) { $i++; };
        $bar = function ($request, $response) use (&$i) {
            $i++;
            $response->send("test");
        };

        $router = new Router;
        $router->route("GET", "/{name}/{age}", $foo, $bar, $foo);
        $router->prefix("/genius");
        $mock = $this->mockServer(Server::STARTING);
        $router->update($mock);

        $ireq = new InternalRequest;
        $request = new Request($ireq);
        $ireq->locals = [];
        $ireq->method = "GET";
        $ireq->uriPath = "/genius/daniel/32";
        $response = $this->mockResponse();

        $this->assertFalse($router->filter($ireq)->valid());
        $multiAction = $router($request, $response);

        Promise\wait(new Coroutine($multiAction));

        $this->assertSame(3, $i);
        $this->assertSame(["name" => "daniel", "age" => "32"], $ireq->locals["aerys.routeArgs"]);
    }

    public function testCachedFilterRoute() {
        $filter = new class implements Filter {
            public function filter(InternalRequest $ireq) {
                $data = yield;
                $data = "filter + " . yield $data;
                while (true) {
                    $data = yield $data;
                }
            }
        };
        $action = function ($req, $res) {
            $res->end("action");
        };

        $router = new Router;
        $router->route("GET", "/", $filter, $action);
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

            $request = new Request($ireq);
            $filter = \Aerys\responseFilter([[$router, "filter"]], $ireq);
            $filter->current();
            $response = new StandardResponse(\Aerys\responseCodec($filter, $ireq), new Client);

            $router($request, $response);

            $this->assertEquals("filter + action", $received);
        }
    }
}
