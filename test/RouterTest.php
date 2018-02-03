<?php

namespace Aerys\Test;

use Aerys\CallableResponder;
use Aerys\Client;
use Aerys\Options;
use Aerys\Request;
use Aerys\Responder;
use Aerys\Response;
use Aerys\RouteArguments;
use Aerys\Router;
use Aerys\Server;
use Amp\Failure;
use Amp\Http\Status;
use Amp\Promise;
use Amp\Uri\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface as PsrLogger;

class RouterTest extends TestCase {
    public function mockServer(): Server {
        $options = new Options;

        $mock = $this->getMockBuilder(Server::class)
            ->setConstructorArgs([$this->createMock(Responder::class), $options, $this->createMock(PsrLogger::class)])
            ->getMock();

        $mock->method("getOptions")
            ->willReturn($options);

        return $mock;
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Aerys\Router::addRoute() requires a non-empty string HTTP method at Argument 1
     */
    public function testRouteThrowsOnEmptyMethodString() {
        $router = new Router;
        $router->addRoute("", "/uri", new CallableResponder(function () {}));
    }

    public function testUpdateFailsIfStartedWithoutAnyRoutes() {
        $router = new Router;
        $mock = $this->mockServer();
        $result = $router->onStart($mock);
        $this->assertInstanceOf(Failure::class, $result);
        $i = 0;
        $result->onResolve(function (\Throwable $e) use (&$i) {
            $i++;
            $this->assertInstanceOf("Error", $e);
            $this->assertSame("Router start failure: no routes registered", $e->getMessage());
        });
        $this->assertSame($i, 1);
    }

    public function testUseCanonicalRedirector() {
        $router = new Router;
        $router->addRoute("GET", "/{name}/{age}/?", new CallableResponder(function (Request $req) use (&$routeArgs) {
            $routeArgs = $req->getAttribute(Router::class);
            return new Response;
        }));
        $router->prefix("/mediocre-dev");
        $mock = $this->mockServer();
        Promise\wait($router->onStart($mock));

        $request = new Request($this->createMock(Client::class), "GET", new Uri("/mediocre-dev/bob/19/"));

        /** @var \Aerys\Response $response */
        $response = Promise\wait($router->respond($request));

        $this->assertEquals(Status::FOUND, $response->getStatus());
        $this->assertEquals("/mediocre-dev/bob/19", $response->getHeader("location"));

        $request = new Request($this->createMock(Client::class), "GET", new Uri("/mediocre-dev/bob/19"));

        $response = Promise\wait($router->respond($request));

        $this->assertEquals(Status::OK, $response->getStatus());
        $this->assertSame(["name" => "bob", "age" => "19"], $routeArgs);
    }
}
