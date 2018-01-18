<?php

namespace Aerys\Test;

use Aerys\Body;
use Aerys\CallableResponder;
use Aerys\HttpStatus;
use Aerys\Internal;
use Aerys\Internal\HttpDriver;
use Aerys\Logger;
use Aerys\Options;
use Aerys\Request;
use Aerys\Responder;
use Aerys\Response;
use Aerys\Server;
use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\IteratorStream;
use Amp\Deferred;
use Amp\Emitter;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Socket;
use Amp\Uri\Uri;

// @TODO test communication on half-closed streams (both ways) [also with yield message] (also with HTTP/1 pipelining...)

class ServerTest extends TestCase {
    public function tryRequest(array $emit, $responder) {
        if (!$responder instanceof Responder) {
            $responder = new CallableResponder($responder);
        }

        $gen = $this->tryIterativeRequest($responder);
        foreach ($emit as $part) {
            $gen->send($part);
        }
        return $gen->current();
    }


    public function tryIterativeRequest(Responder $responder): \Generator {
        $driver = new class($this) implements HttpDriver {
            private $test;
            private $emitter;
            public $response;
            public $body;
            private $client;

            public function __construct($test) {
                $this->test = $test;
                $this->client = new Internal\Client;
                $this->client->serverPort = 80;
                $this->client->httpDriver = $this;
            }

            public function setup(Server $server, callable $resultEmitter, callable $errorEmitter, callable $write) {
                $this->emitter = $resultEmitter;
            }

            public function writer(Internal\Client $client, Response $response, Request $ireq = null): \Generator {
                $this->response = $response;
                $this->body = "";
                do {
                    $this->body .= $part = yield;
                } while ($part !== null);
            }

            public function parser(Internal\Client $client): \Generator {
                $this->test->fail("We shouldn't be invoked the parser with no actual clients");
            }

            public function emit(Request $request) {
                ($this->emitter)($this->client, $request);
            }
        };

        $logger = $this->createMock(Logger::class);

        $options = (new Options)
            ->withDebugMode(true);

        $server = new Server($responder, $options, $logger);

        (function () use ($driver) {
            $this->setupHttpDriver($driver);
        })->call($server);

        $server->start();

        $part = yield;
        while (1) {
            $driver->emit($part);
            $part = yield [&$driver->response, &$driver->body];
        }
    }

    public function testBasicRequest() {
        $request = new Request(
            "GET", // method
            new Uri("http://localhost:80/foo"), // URI
            ["host" => ["localhost"]] // headers
        );

        /** @var \Aerys\Response $response */
        list($response, $body) = $this->tryRequest([$request], function (Request $req) {
            $this->assertSame("localhost", $req->getHeader("Host"));
            $this->assertSame("/foo", $req->getUri()->getPath());
            $this->assertSame("GET", $req->getMethod());
            $this->assertSame("", yield $req->getBody()->buffer());

            return new Response(new InMemoryStream("message"), ["FOO" => "bar"]);
        });

        $status = HttpStatus::OK;
        $this->assertSame($status, $response->getStatus());
        $this->assertSame(HttpStatus::getReason($status), $response->getReason());
        $this->assertSame("bar", $response->getHeader("foo"));

        $this->assertSame("message", $body);
    }

    public function testStreamRequest() {
        $emitter = new Emitter;

        $request = new Request(
            "GET", // method
            new Uri("http://localhost:80/foo"), // URI
            ["host" => ["localhost"]], // headers
            new Body(new IteratorStream($emitter->iterate())) // body
        );

        $emitter->emit("fooBar");
        $emitter->emit("BUZZ!");
        $emitter->complete();

        /** @var \Aerys\Response $response */
        list($response, $body) = $this->tryRequest([$request], function (Request $req) {
            $buffer = "";
            while ((null !== $chunk = yield $req->getBody()->read())) {
                $buffer .= $chunk;
            }
            return new Response(new InMemoryStream($buffer));
        });

        $status = HttpStatus::OK;
        $this->assertSame($status, $response->getStatus());
        $this->assertSame(HttpStatus::getReason($status), $response->getReason());

        $this->assertSame("fooBarBUZZ!", $body);
    }

    /**
     * @dataProvider providePreResponderRequests
     */
    public function testPreResponderFailures(Request $request, int $status) {
        /** @var \Aerys\Response $response */
        list($response) = $this->tryRequest([$request], function (Request $req) {
            $this->fail("We should already have failed and never invoke the responder...");
        });

        $this->assertEquals($status, $response->getStatus());
    }

    public function providePreResponderRequests() {
        return [
            [
                new Request(
                    "OPTIONS", // method
                    new Uri("http://localhost:80/"), // URI
                    ["host" => ["localhost"]], // headers
                    null, // body
                    "*" // target
                ),
                HttpStatus::NO_CONTENT
            ],
            [
                new Request(
                    "TRACE", // method
                    new Uri("http://localhost:80/"), // URI
                    ["host" => ["localhost"]] // headers
                ),
                HttpStatus::METHOD_NOT_ALLOWED
            ],
            [
                new Request(
                    "UNKNOWN", // method
                    new Uri("http://localhost:80/"), // URI
                    ["host" => ["localhost"]] // headers
                ),
                HttpStatus::NOT_IMPLEMENTED
            ],
        ];
    }

    public function testOptionsRequest() {
        $request = new Request(
            "OPTIONS", // method
            new Uri("http://localhost:80/"), // URI
            ["host" => ["localhost"]], // headers
            null, // body
            "*" // target
        );

        /** @var \Aerys\Response $response */
        list($response) = $this->tryRequest([$request], function (Request $req) {
            $this->fail("We should already have failed and never invoke the responder...");
        });

        $this->assertSame(HttpStatus::NO_CONTENT, $response->getStatus());
        $this->assertSame(implode(", ", (new Options)->getAllowedMethods()), $response->getHeader("allow"));
    }

    public function testError() {
        $request = new Request(
            "GET", // method
            new Uri("http://localhost:80/foo"), // URI
            ["host" => ["localhost"]] // headers
        );

        /** @var \Aerys\Response $response */
        list($response) = $this->tryRequest([$request], function (Request $req) {
            throw new \Exception;
        });

        $this->assertSame(HttpStatus::INTERNAL_SERVER_ERROR, $response->getStatus());
    }

    public function startServer(callable $parser, bool $tls, bool $unixSocket = false) {
        if ($unixSocket) {
            $address = tempnam(sys_get_temp_dir(), "aerys_server_test");
            $uri = "unix://" . $address;
            $port = 0;
        } else {
            if (!$server = @stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr)) {
                $this->markTestSkipped("Couldn't get a free port from the local ephemeral port range");
            }
            $uri = stream_socket_get_name($server, $wantPeer = false);
            list($address, $port) = explode(':', $uri, 2);
            fclose($server);
        }

        $driver = new class($this) implements HttpDriver {
            private $test;
            private $write;
            public $parser;

            public function __construct($test) {
                $this->test = $test;
            }

            public function setup(Server $server, callable $resultEmitter, callable $errorEmitter, callable $write) {
                $this->write = $write;
            }

            public function writer(Internal\Client $client, Response $response, Request $request = null): \Generator {
                $this->test->fail("We shouldn't be invoked the writer when not dispatching requests");
                yield;
            }

            public function parser(Internal\Client $client): \Generator {
                yield from ($this->parser)($client, $this->write);
            }
        };

        $logger = $this->createMock(Logger::class);

        $options = (new Options)
            ->withDebugMode(true);

        $server = new Server($this->createMock(Responder::class), $options, $logger);

        (function () use ($driver) {
            $this->setupHttpDriver($driver);
        })->call($server);

        $server->expose($address, $port);

        if ($tls) {
            $tlsContext = new Socket\ServerTlsContext;
            $tlsContext = $tlsContext->withDefaultCertificate(new Socket\Certificate(__DIR__ . "/server.pem"));
            $tlsContext = $tlsContext->withoutPeerVerification();
            $server->encrypt($tlsContext);
        }

        $driver->parser = $parser;
        yield $server->start();
        return [$uri, $server];
    }

    public function provideFalseTrueUnixDomainSocket() {
        return ["tcp" => [false], "unix" => [true]];
    }

    /**
     * @dataProvider provideFalseTrueUnixDomainSocket
     */
    public function testUnencryptedIO($useUnixDomainSocket) {
        Loop::run(function () use ($useUnixDomainSocket) {
            list($address, $server) = yield from $this->startServer(function (Internal\Client $client, callable $write) {
                $this->assertFalse($client->isEncrypted);

                $client->pendingResponses = 1;

                $this->assertEquals("a", yield);
                $this->assertEquals("b", yield);
                $write($client, "c", false);
                $write($client, "d", true);
            }, false, $useUnixDomainSocket);

            /** @var \Amp\Socket\Socket $client */
            $client = yield Socket\connect($address);
            yield $client->write("a");
            // give readWatcher a chance
            $deferred = new Deferred;
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

            $deferred = new Deferred;
            list($address) = yield from $this->startServer(function (Internal\Client $client, $write) use ($deferred) {
                try {
                    $this->assertTrue($client->isEncrypted);
                    $this->assertEquals(0, $client->isDead);

                    do {
                        $dump = ($dump ?? "") . yield;
                    } while (strlen($dump) <= 65537);
                    $this->assertEquals("1a", substr($dump, -2));
                    $client->pendingResponses = 1;
                    $write($client, "b", true);
                    yield;
                } catch (\Throwable $e) {
                    $deferred->fail($e);
                } finally {
                    if (isset($e)) {
                        return;
                    }

                    Loop::defer(function () use ($client, $deferred) {
                        try {
                            $this->assertEquals(Internal\Client::CLOSED_RDWR, $client->isDead);
                            $deferred->resolve();
                        } catch (\Throwable $e) {
                            $deferred->fail($e);
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
