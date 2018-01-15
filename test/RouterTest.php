<?php

namespace Aerys\Test;

use Aerys\CallableResponder;
use Aerys\ErrorHandler;
use Aerys\HttpStatus;
use Aerys\Internal;
use Aerys\Logger;
use Aerys\Options;
use Aerys\Request;
use Aerys\Responder;
use Aerys\Response;
use Aerys\Router;
use Aerys\Server;
use Amp\Failure;
use Amp\Promise;
use Amp\Uri\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface as PsrLogger;

class RouterTest extends TestCase {
    public function mockServer(): Server {
        return $this->getMockBuilder(Server::class)
            ->setConstructorArgs([$this->createMock(Responder::class), new Options, $this->createMock(PsrLogger::class)])
            ->getMock();
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Aerys\Router::route() requires a non-empty string HTTP method at Argument 1
     */
    public function testRouteThrowsOnEmptyMethodString() {
        $router = new Router;
        $router->route("", "/uri", new CallableResponder(function () {}));
    }

    public function testUpdateFailsIfStartedWithoutAnyRoutes() {
        $router = new Router;
        $mock = $this->mockServer();
        $result = $router->onStart($mock, $this->createMock(Logger::class), $this->createMock(ErrorHandler::class));
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
        $router->route("GET", "/{name}/{age}/?", new CallableResponder(function (Request $req) use (&$routeArgs) {
            $routeArgs = $req->getAttribute(Router::class);
            return new Response;
        }));
        $router->prefix("/mediocre-dev");
        $mock = $this->mockServer();
        Promise\wait($router->onStart($mock, $this->createMock(Logger::class), $this->createMock(ErrorHandler::class)));

        $ireq = new Internal\ServerRequest;
        $request = new Request($ireq);
        $ireq->method = "GET";
        $ireq->uri = new Uri("/mediocre-dev/bob/19/");

        /** @var \Aerys\Response $response */
        $response = Promise\wait($router->respond($request));

        $this->assertEquals(HttpStatus::FOUND, $response->getStatus());
        $this->assertEquals("/mediocre-dev/bob/19", $response->getHeader("location"));

        $ireq = new Internal\ServerRequest;
        $request = new Request($ireq);
        $ireq->method = "GET";
        $ireq->uri = new Uri("/mediocre-dev/bob/19");

        $response = Promise\wait($router->respond($request));

        $this->assertEquals(HttpStatus::OK, $response->getStatus());
        $this->assertSame(["name" => "bob", "age" => "19"], $routeArgs);
    }
}
