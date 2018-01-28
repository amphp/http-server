<?php

namespace Aerys\Test\Middleware;

use Aerys\CallableResponder;
use Aerys\Client;
use Aerys\Middleware;
use Aerys\Request;
use Aerys\Responder;
use Aerys\Response;
use Aerys\Response\HtmlResponse;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use Amp\Uri\Uri;
use function Aerys\Middleware\stack;
use function Amp\Promise\wait;

final class StackTestAttribute {
    public $buffer;
}

class StackTest extends TestCase {
    public function testStackAppliesMiddlewaresInCorrectOrder() {
        $request = new Request($this->createMock(Client::class), "GET", new Uri("/foobar"));

        $stack = stack(new CallableResponder(function (Request $request) {
            $response = new HtmlResponse("OK");
            $response->setHeader("stack", $request->get(StackTestAttribute::class)->buffer);

            return $response;
        }), new class implements Middleware {
            public function process(Request $request, Responder $responder): Promise {
                $attr = new StackTestAttribute;
                $attr->buffer = "a";
                $request->attach($attr);

                return $responder->respond($request);
            }
        }, new class implements Middleware {
            public function process(Request $request, Responder $responder): Promise {
                $request->get(StackTestAttribute::class)->buffer .= "b";

                return $responder->respond($request);
            }
        });

        /** @var Response $response */
        $response = wait($stack->respond($request));

        $this->assertSame("ab", $response->getHeader("stack"));
    }
}
