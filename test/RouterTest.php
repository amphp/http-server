<?php

namespace Aerys\Test;

use Aerys\Internal;
use Aerys\Options;
use Aerys\Request;
use Aerys\Response;
use Aerys\Router;
use Aerys\Server;
use Amp\Promise;
use Amp\Uri\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface as PsrLogger;

class RouterTest extends TestCase {
    public function mockServer($state) {
        return new class($state, $this->createMock(Internal\VhostContainer::class), $this->createMock(PsrLogger::class)) extends Server {
            private $state;
            private $options;
            public function __construct($state, $vhostContainer, $logger) {
                $this->state = $state;
                $this->options = new Options;
                parent::__construct($this->options, $vhostContainer, $logger, new Internal\Ticker($logger));
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
     * @expectedExceptionMessage Responder at Argument 3 must be callable or an instance of
     */
    public function testRouteThrowsOnInvalidResponder() {
        $router = new Router;
        $router->route("GET", "/uri", 1);
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
        $router->route("GET", "/{name}/{age}/?", function (Request $req, array $args) use (&$routeArgs) {
            $routeArgs = $args;
            return new Response\EmptyResponse;
        });
        $router->prefix("/mediocre-dev");
        $mock = $this->mockServer(Server::STARTING);
        $router->boot($mock, $this->createMock(PsrLogger::class));
        $router->update($mock);

        $ireq = new Internal\Request;
        $request = new Request($ireq);
        $ireq->method = "GET";
        $ireq->uri = new Uri("/mediocre-dev/bob/19/");

        /** @var \Aerys\Response $response */
        $response = Promise\wait($router->respond($request));

        $this->assertEquals(\Aerys\HTTP_STATUS["FOUND"], $response->getStatus());
        $this->assertEquals("/mediocre-dev/bob/19", $response->getHeader("location"));

        $ireq = new Internal\Request;
        $request = new Request($ireq);
        $ireq->method = "GET";
        $ireq->uri = new Uri("/mediocre-dev/bob/19");

        $response = Promise\wait($router->respond($request));

        $this->assertEquals(\Aerys\HTTP_STATUS["OK"], $response->getStatus());
        $this->assertSame(["name" => "bob", "age" => "19"], $routeArgs);
    }
}
