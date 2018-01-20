<?php

namespace Aerys\Test\Middleware;

use Aerys\CallableResponder;
use Aerys\Client;
use Aerys\Middleware\Middleware;
use Aerys\Request;
use Aerys\Responder;
use Aerys\Response;
use Aerys\Response\HtmlResponse;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use Amp\Uri\Uri;
use function Aerys\Middleware\stack;
use function Amp\Promise\wait;

class StackTest extends TestCase {
    public function testStackAppliesMiddlewaresInCorrectOrder() {
        $request = new Request($this->createMock(Client::class), "GET", new Uri("/foobar"));

        $stack = stack(new CallableResponder(function (Request $request) {
            $response = new HtmlResponse("OK");
            $response->setHeader("stack", $request->getAttribute("stack"));

            return $response;
        }), new class implements Middleware {
            public function process(Request $request, Responder $responder): Promise {
                $request->setAttribute("stack", "a");

                return $responder->respond($request);
            }
        }, new class implements Middleware {
            public function process(Request $request, Responder $responder): Promise {
                $request->setAttribute("stack", $request->getAttribute("stack") . "b");

                return $responder->respond($request);
            }
        });

        /** @var Response $response */
        $response = wait($stack->respond($request));

        $this->assertSame("ab", $response->getHeader("stack"));
    }
}
