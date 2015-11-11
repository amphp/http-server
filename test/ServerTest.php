<?php

namespace Aerys\Test;

use Aerys\Client;
use Aerys\HttpDriver;
use Aerys\InternalRequest;
use Aerys\Logger;
use Aerys\Options;
use Aerys\Request;
use Aerys\Response;
use Aerys\Server;
use Aerys\Ticker;
use Aerys\Vhost;
use Aerys\VhostContainer;

class ServerTest extends \PHPUnit_Framework_TestCase {
    function tryRequest($emit, $responder, $middlewares = []) {
        $gen = $this->tryIterativeRequest($responder, $middlewares);
        foreach ($emit as $part) {
            $gen->send($part);
        }
        return $gen->current();
    }


    function tryIterativeRequest($responder, $middlewares = []) {
        $vhosts = new VhostContainer;
        $vhosts->use(new Vhost("", [["0.0.0.0", 80], ["::", 80]], $responder, $middlewares));
        yield from $this->tryLowLevelRequest($vhosts, $responder, $middlewares);
    }

    function tryLowLevelRequest($vhosts, $responder, $middlewares) {
        $logger = new class extends Logger { protected function output(string $message) { /* /dev/null */ } };
        $server = new Server(new Options, $vhosts, $logger, new Ticker($logger), [$driver = new class($this) implements HttpDriver {
            private $test;
            private $emit;
            public $headers;
            public $body;
            private $client;

            public function __construct($test) {
                $this->test = $test;
                $this->client = new Client;
                $this->client->httpDriver = $this;
            }

            public function __invoke(callable $emit, callable $write) {
                $this->emit = $emit;
                return $this;
            }

            public function versions(): array {
                return ["2.0"];
            }

            public function filters(InternalRequest $ireq): array {
                return $ireq->vhost->getFilters();
            }

            public function writer(InternalRequest $ireq): \Generator {
                $this->test->assertSame($this->client, $ireq->client);

                $this->headers = yield;
                $this->body = "";
                do {
                    $this->body .= $part = yield;
                } while ($part !== null);
            }

            public function parser(Client $client): \Generator {
                $this->test->fail("We shouldn't be invoked the parser with no actual clients");
            }

            public function emit($emit) {
                ($this->emit)($emit, $this->client);
            }
        }]);

        $part = yield;
        while (1) {
            $driver->emit($part);
            $part = yield [$driver->headers, $driver->body];
        }
    }

    function testBasicRequest() {
        $parseResult = [
            "id" => 2,
            "trace" => [["host", "localhost"]],
            "protocol" => "2.0",
            "method" => "GET",
            "uri" => "http://localhost/foo",
            "headers" => ["host" => ["localhost"]],
            "body" => "",
        ];

        $order = 0;
        list($headers, $body) = $this->tryRequest([[HttpDriver::RESULT, $parseResult, null]], function (Request $req, Response $res) use (&$order) {
            $this->assertEquals(2, ++$order);
            $this->assertEquals("localhost", $req->getHeader("Host"));
            $this->assertEquals("/foo", $req->getUri());
            $this->assertEquals("GET", $req->getMethod());
            $this->assertEquals("", yield $req->getBody());
            $res->setHeader("FOO", "bar");
            $res->end("message");
            $this->assertEquals(4, ++$order);
        }, [function (InternalRequest $ireq) use (&$order) {
            $this->assertEquals(1, ++$order);
            $this->assertEquals(2, $ireq->streamId);
            $headers = yield;
            $this->assertEquals(["bar"], $headers["foo"]);
            $headers["foo"] = ["baz"];
            $this->assertEquals(3, ++$order);
            return $headers;
        }]);

        $this->assertEquals([":status" => 200, ":reason" => null, "foo" => ["baz"], ":aerys-entity-length" => 7], $headers);
        $this->assertEquals("message", $body);
    }

    function testStreamRequest() {
        $parseResult = [
            "id" => 2,
            "trace" => [["host", "localhost"]],
            "protocol" => "2.0",
            "method" => "POST",
            "uri" => "http://localhost/foo",
            "headers" => ["host" => ["localhost"]],
            "body" => "",
        ];

        list($headers, $body) = $this->tryRequest([
            [HttpDriver::ENTITY_HEADERS, $parseResult, null],
            [HttpDriver::ENTITY_PART, ["id" => 2, "protocol" => "2.0", "body" => "fooBar"], null],
            [HttpDriver::ENTITY_PART, ["id" => 2, "protocol" => "2.0", "body" => "BAZ!"], null],
            [HttpDriver::ENTITY_RESULT, ["id" => 2, "protocol" => "2.0"], null],
        ], function (Request $req, Response $res) {
            while (yield $req->getBody()->valid()) {
                $res->stream($req->getBody()->consume());
            }
            $res->end();
        }, [function (InternalRequest $ireq) {
            $headers = yield;
            $this->assertEquals("fooBar", yield $headers);
            $this->assertEquals("BAZ!", yield "fooBar");
            return "BUZZ!";
        }]);

        $this->assertEquals([":status" => 200, ":reason" => null, ":aerys-entity-length" => '*'], $headers);
        $this->assertEquals("fooBarBUZZ!", $body);
    }

    function testDelayedStreamRequest() {
        $parseResult = [
            "id" => 2,
            "trace" => [["host", "localhost"]],
            "protocol" => "2.0",
            "method" => "POST",
            "uri" => "http://localhost/foo",
            "headers" => ["host" => ["localhost"]],
        ];

        list($headers, $body) = $this->tryRequest([[HttpDriver::RESULT, $parseResult, null]], function (Request $req, Response $res) {
            $this->assertEquals("", yield $req->getBody());
            $res->stream("fooBar");
            $res->stream("BAZ!");
            $res->end();
        }, [function (InternalRequest $ireq) {
            $headers = yield;
            $this->assertEquals("fooBar", yield);
            $this->assertEquals("BAZ!", yield);
            $this->assertNull(yield);
            yield $headers;
            return "Success!";
        }]);

        $this->assertEquals([":status" => 200, ":reason" => null, ":aerys-entity-length" => '*'], $headers);
        $this->assertEquals("Success!", $body);
    }

    function testFlushRequest() {
        $parseResult = [
            "id" => 2,
            "trace" => [["host", "localhost"]],
            "protocol" => "2.0",
            "method" => "GET",
            "uri" => "http://localhost/foo",
            "headers" => ["host" => ["localhost"]],
            "body" => "",
        ];

        list($headers, $body) = $this->tryRequest([[HttpDriver::RESULT, $parseResult, null]], function (Request $req, Response $res) {
            $res->stream("Bob");
            $res->flush();
            $res->stream(" ");
            $res->end("19!");
        }, [function (InternalRequest $ireq) {
            $headers = yield;
            $this->assertEquals("Bob", yield);
            $this->assertFalse(yield);
            $this->assertFalse(yield $headers);
            $this->assertEquals(" ", yield "Weinand");
            return " is ";
        }]);

        $this->assertEquals([":status" => 200, ":reason" => null, ":aerys-entity-length" => '*'], $headers);
        $this->assertEquals("Weinand is 19!", $body);
    }

}