<?php

namespace Amp\Http\Server\Test\Middleware;

use Amp\Http\Server\CallableResponder;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\Responder;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use League\Uri;
use function Amp\Http\Server\Middleware\stack;
use function Amp\Promise\wait;

class StackTest extends TestCase {
    public function testStackAppliesMiddlewaresInCorrectOrder() {
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/foobar"));

        $stack = stack(new CallableResponder(function (Request $request) {
            $response = new Response(Status::OK, [], "OK");
            $response->setHeader("stack", $request->getAttribute(StackTest::class));

            return $response;
        }), new class implements Middleware {
            public function process(Request $request, Responder $responder): Promise {
                $request->setAttribute(StackTest::class, "a");

                return $responder->respond($request);
            }
        }, new class implements Middleware {
            public function process(Request $request, Responder $responder): Promise {
                $request->setAttribute(StackTest::class, $request->getAttribute(StackTest::class) . "b");

                return $responder->respond($request);
            }
        });

        /** @var Response $response */
        $response = wait($stack->respond($request));

        $this->assertSame("ab", $response->getHeader("stack"));
    }
}
