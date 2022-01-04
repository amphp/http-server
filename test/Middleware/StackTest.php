<?php

namespace Amp\Http\Server\Test\Middleware;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\PHPUnit\AsyncTestCase;
use League\Uri;
use function Amp\Http\Server\Middleware\stack;

class StackTest extends AsyncTestCase
{
    public function testStackAppliesMiddlewaresInCorrectOrder(): void
    {
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/foobar"));

        $stack = stack(new ClosureRequestHandler(function (Request $request) {
            $response = new Response(Status::OK, [], "OK");
            $response->setHeader("stack", $request->getAttribute(StackTest::class));

            return $response;
        }), new class implements Middleware {
            public function handleRequest(Request $request, RequestHandler $requestHandler): Response
            {
                $request->setAttribute(StackTest::class, "a");

                return $requestHandler->handleRequest($request);
            }
        }, new Middleware\ClosureMiddleware(function (Request $request, RequestHandler $requestHandler) {
            $request->setAttribute(StackTest::class, $request->getAttribute(StackTest::class) . "b");

            return $requestHandler->handleRequest($request);
        }));

        $response = $stack->handleRequest($request);

        self::assertSame("ab", $response->getHeader("stack"));
    }

    public function testEmptyMiddlewareSet(): void
    {
        $mock = $this->createMock(RequestHandler::class);
        self::assertSame($mock, stack($mock));
    }
}
