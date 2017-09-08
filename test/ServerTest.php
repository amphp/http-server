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
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Socket;

// @TODO test communication on half-closed streams (both ways) [also with yield message] (also with HTTP/1 pipelining...)

class ServerTest extends TestCase {
    public function tryRequest($emit, $responder, $middlewares = []) {
        $gen = $this->tryIterativeRequest($responder, $middlewares);
        foreach ($emit as $part) {
            $gen->send($part);
        }
        return $gen->current();
    }


    public function tryIterativeRequest($responder, $middlewares = []) {
        $vhosts = new VhostContainer($driver = new class($this) implements HttpDriver {
            private $test;
            private $emitters;
            public $headers;
            public $body;
            private $client;

            public function __construct($test) {
                $this->test = $test;
                $this->client = new Client;
                $this->client->serverPort = 80;
                $this->client->httpDriver = $this;
            }

            public function setup(array $parseEmitters, callable $write) {
                $this->emitters = $parseEmitters;
            }

            public function filters(InternalRequest $ireq, array $filters): array {
                return $filters;
            }

            public function writer(InternalRequest $ireq): \Generator {
                $this->test->assertSame($this->client, $ireq->client);

                $this->headers = yield;
                $this->body = "";
                do {
                    $this->body .= $part = yield;
                } while ($part !== null);
            }

            public function upgradeBodySize(InternalRequest $ireq) {
            }

            public function parser(Client $client): \Generator {
                $this->test->fail("We shouldn't be invoked the parser with no actual clients");
            }

            public function emit($emit) {
                $type = array_shift($emit);
                foreach ($this->emitters as $key => $emitter) {
                    if ($key & $type) {
                        if ($emit[0] instanceof InternalRequest) {
                            $emit[0]->client = $this->client;
                            $emitter(...$emit);
                        } else {
                            $emitter($this->client, ...$emit);
                        }
                    }
                }
            }
        });
        $vhosts->use(new Vhost("localhost", [["0.0.0.0", 80], ["::", 80]], $responder, $middlewares));

        $logger = new class extends Logger {
            protected function output(string $message) { /* /dev/null */
            }
        };
        $server = new Server(new Options, $vhosts, $logger, new Ticker($logger));
        $driver->setup((function () { return $this->createHttpDriverHandlers(); })->call($server), $this->createCallback(0));
        $part = yield;
        while (1) {
            $driver->emit($part);
            $part = yield [&$driver->headers, &$driver->body];
        }
    }

    public function newIreq() {
        $ireq = new InternalRequest;
        $ireq->streamId = 2;
        $ireq->trace = [["host", "localhost"]];
        $ireq->protocol = "2.0";
        $ireq->method = "GET";
        $ireq->uri = "/foo";
        $ireq->uriScheme = "http";
        $ireq->uriHost = "localhost";
        $ireq->uriPort = 80;
        $ireq->uriPath = "/foo";
        $ireq->uriQuery = "";
        $ireq->headers = ["host" => ["localhost"]];
        return $ireq;
    }

    public function testBasicRequest() {
        $ireq = $this->newIreq();

        $order = 0;
        list($headers, $body) = $this->tryRequest([[HttpDriver::RESULT, $ireq]], function (Request $req, Response $res) use (&$order) {
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

        $this->assertEquals([":status" => 200, ":reason" => "OK", "foo" => ["baz"], ":aerys-entity-length" => 7], $headers);
        $this->assertEquals("message", $body);
    }

    public function testStreamRequest() {
        $ireq = $this->newIreq();

        list($headers, $body) = $this->tryRequest([
            [HttpDriver::ENTITY_HEADERS, $ireq],
            [HttpDriver::ENTITY_PART, "fooBar", 2],
            [HttpDriver::ENTITY_PART, "BAZ!", 2],
            [HttpDriver::ENTITY_RESULT, 2],
        ], function (Request $req, Response $res) {
            while (($chunk = yield $req->getBody()->read()) != "") {
                $res->write($chunk);
            }
            $res->end();
        }, [function (InternalRequest $ireq) {
            $headers = yield;
            $this->assertEquals("fooBar", yield $headers);
            $this->assertEquals("BAZ!", yield "fooBar");
            return "BUZZ!";
        }]);

        $this->assertEquals([":status" => 200, ":reason" => "OK", ":aerys-entity-length" => '*'], $headers);
        $this->assertEquals("fooBarBUZZ!", $body);
    }

    public function testDelayedStreamRequest() {
        $ireq = $this->newIreq();
        $ireq->method = "POST";

        list($headers, $body) = $this->tryRequest([[HttpDriver::RESULT, $ireq]], function (Request $req, Response $res) {
            $this->assertEquals("", yield $req->getBody());
            $res->write("fooBar");
            $res->write("BAZ!");
            $res->end();
        }, [function (InternalRequest $ireq) {
            $this->assertSame("POST", $ireq->method);
            $headers = yield;
            $this->assertEquals("fooBar", yield);
            $this->assertEquals("BAZ!", yield);
            $this->assertNull(yield);
            yield $headers;
            return "Success!";
        }]);

        $this->assertEquals([":status" => 200, ":reason" => "OK", ":aerys-entity-length" => '*'], $headers);
        $this->assertEquals("Success!", $body);
    }

    public function testFlushRequest() {
        $ireq = $this->newIreq();

        list($headers, $body) = $this->tryRequest([[HttpDriver::RESULT, $ireq]], function (Request $req, Response $res) {
            $res->write("Bob");
            $res->flush();
            $res->write(" ");
            $res->end("19!");
        }, [function (InternalRequest $ireq) {
            $headers = yield;
            $this->assertEquals("Bob", yield);
            $this->assertFalse(yield);
            $this->assertFalse(yield $headers);
            $this->assertEquals(" ", yield "Weinand");
            return " is ";
        }]);

        $this->assertEquals([":status" => 200, ":reason" => "OK", ":aerys-entity-length" => '*'], $headers);
        $this->assertEquals("Weinand is 19!", $body);
    }

    /**
     * @dataProvider providePreResponderHeaders
     */
    public function testPreResponderFailures($changes, $status) {
        $ireq = $this->newIreq();
        foreach ($changes as $key => $change) {
            $ireq->$key = $change;
        }

        list($headers) = $this->tryRequest([[HttpDriver::RESULT, $ireq]], function (Request $req, Response $res) { $this->fail("We should already have failed and never invoke the responder..."); });

        $this->assertEquals($status, $headers[":status"]);
    }

    public function providePreResponderHeaders() {
        return [
            [["headers" => ["host" => "undefined"], "uriHost" => "undefined"], \Aerys\HTTP_STATUS["BAD_REQUEST"]],
            [["method" => "NOT_ALLOWED"], \Aerys\HTTP_STATUS["METHOD_NOT_ALLOWED"]],
        ];
    }

    public function testOptionsRequest() {
        $ireq = $this->newIreq();
        $ireq->uri = $ireq->uriPath = "*";
        $ireq->method = "OPTIONS";

        list($headers) = $this->tryRequest([[HttpDriver::RESULT, $ireq]], function (Request $req, Response $res) { $this->fail("We should already have failed and never invoke the responder..."); });

        $this->assertEquals(\Aerys\HTTP_STATUS["OK"], $headers[":status"]);
        $this->assertEquals(implode(",", (new Options)->allowedMethods), $headers["allow"][0]);
    }

    public function testError() {
        $ireq = $this->newIreq();

        list($headers) = $this->tryRequest([[HttpDriver::RESULT, $ireq]], function (Request $req, Response $res) { throw new \Exception; });

        $this->assertEquals(\Aerys\HTTP_STATUS["INTERNAL_SERVER_ERROR"], $headers[":status"]);
    }

    public function testNotFound() {
        $ireq = $this->newIreq();

        list($headers) = $this->tryRequest([[HttpDriver::RESULT, $ireq]], function (Request $req, Response $res) { /* nothing */ });
        $this->assertEquals(\Aerys\HTTP_STATUS["NOT_FOUND"], $headers[":status"]);

        // with coroutine
        $deferred = new \Amp\Deferred;
        $result = $this->tryRequest([[HttpDriver::RESULT, $ireq]], function (Request $req, Response $res) use ($deferred) { yield $deferred->promise(); });
        $deferred->resolve();
        $this->assertEquals(\Aerys\HTTP_STATUS["NOT_FOUND"], $result[0][":status"]);
    }

    public function startServer($parser, $tls, $unixSocket = false) {
        if ($unixSocket) {
            $address = "unix://" . tempnam(sys_get_temp_dir(), "aerys_server_test");
        } else {
            if (!$server = @stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr)) {
                $this->markTestSkipped("Couldn't get a free port from the local ephemeral port range");
            }
            $address = stream_socket_get_name($server, $wantPeer = false);
            fclose($server);
        }

        $driver = new class($this) implements HttpDriver {
            private $test;
            private $write;
            public $parser;

            public function __construct($test) {
                $this->test = $test;
            }

            public function setup(array $parseEmitters, callable $write) {
                $this->write = $write;
            }

            public function filters(InternalRequest $ireq, array $filters): array {
                return $filters;
            }

            public function writer(InternalRequest $ireq): \Generator {
                $this->test->fail("We shouldn't be invoked the writer when not dispatching requests");
            }

            public function parser(Client $client): \Generator {
                yield from ($this->parser)($client, $this->write);
            }

            public function upgradeBodySize(InternalRequest $ireq) {
            }
        };

        $vhosts = new class($tls, $address, $this, $driver) extends VhostContainer {
            public function __construct($tls, $address, $test, $driver) {
                $this->tls = $tls;
                $this->test = $test;
                $this->address = $address;
                $this->driver = $driver;
            }
            public function getBindableAddresses(): array {
                return [$this->address];
            }
            public function getTlsBindingsByAddress(): array {
                return $this->tls ? [$this->address => ["local_cert" => __DIR__."/server.pem", "crypto_method" => STREAM_CRYPTO_METHOD_SSLv23_SERVER]] : [];
            }
            public function selectHost(InternalRequest $ireq): Vhost {
                $this->test->fail("We should never get to dispatching requests here...");
            }
            public function selectHttpDriver($addr, $port) {
                return $this->driver;
            }
            public function count() {
                return 1;
            }
        };

        $logger = new class extends Logger {
            protected function output(string $message) { /* /dev/null */
            }
        };
        $server = new Server(new Options, $vhosts, $logger, new Ticker($logger));
        $driver->setup([], (new \ReflectionClass($server))->getMethod("writeResponse")->getClosure($server));
        $driver->parser = $parser;
        yield $server->start();
        return [$address, $server];
    }

    public function provideFalseTrueUnixDomainSocket() {
        return ["tcp" => [false], "unix" => [true]];
    }

    /**
     * @dataProvider provideFalseTrueUnixDomainSocket
     */
    public function testUnencryptedIO($useUnixDomainSocket) {
        Loop::run(function () use ($useUnixDomainSocket) {
            list($address, $server) = yield from $this->startServer(function (Client $client, $write) {
                $this->assertFalse($client->isEncrypted);

                $client->pendingResponses = 1;

                $this->assertEquals("a", yield);
                $this->assertEquals("b", yield);
                $client->writeBuffer .= "c";
                $write($client, false);
                $client->writeBuffer .= "d";
                $write($client, true);
            }, false, $useUnixDomainSocket);

            /** @var \Amp\Socket\Socket $client */
            $client = yield Socket\connect($address);
            yield $client->write("a");
            // give readWatcher a chance
            $deferred = new \Amp\Deferred;
            Loop::defer(function () use ($deferred) { Loop::defer([$deferred, "resolve"]); });
            yield $deferred->promise();
            yield $client->write("b");
            stream_socket_shutdown($client->getResource(), STREAM_SHUT_WR);
            $this->assertEquals("cd", (yield $client->read()) . (yield $client->read()) . yield $client->read());
            yield $server->stop();
            Loop::stop();
        });
    }

    public function testEncryptedIO() {
        Loop::run(function () {
            try { /* prevent crashes with phpdbg due to SIGPIPE not being handled... */
                Loop::onSignal(defined("SIGPIPE") ? SIGPIPE : 13, function () {});
            } catch (Loop\UnsupportedFeatureException $e) {
            }

            $deferred = new \Amp\Deferred;
            list($address) = yield from $this->startServer(function (Client $client, $write) use ($deferred) {
                try {
                    $this->assertTrue($client->isEncrypted);
                    $this->assertEquals(0, $client->isDead);

                    do {
                        $dump = ($dump ?? "") . yield;
                    } while (strlen($dump) <= 65537);
                    $this->assertEquals("1a", substr($dump, -2));
                    $client->writeBuffer = "b";
                    $client->pendingResponses = 1;
                    $write($client, true);
                    yield;
                } catch (\Throwable $e) {
                    $deferred->fail($e);
                } finally {
                    if (isset($e)) {
                        return;
                    }
                    Loop::defer(function () use ($client, $deferred) {
                        try {
                            $this->assertEquals(Client::CLOSED_RDWR, $client->isDead);
                        } catch (\Throwable $e) {
                            $deferred->fail($e);
                        }
                        if (empty($e)) {
                            $deferred->resolve();
                        }
                    });
                }
            }, true);

            $context = (new Socket\ClientTlsContext)->withoutPeerVerification();

            /** @var \Amp\Socket\Socket $client */
            $client = yield Socket\cryptoConnect($address, null, $context);
            yield $client->write(str_repeat("1", 65537)); // larger than one TCP frame
            yield $client->write("a");
            $this->assertEquals("b", yield $client->read());
            $client->close();

            yield $deferred->promise();
            Loop::stop();
        });
    }
}
