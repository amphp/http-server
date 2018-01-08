<?php

namespace Aerys\Test;

use Aerys\CallableResponder;
use Aerys\Host;
use Aerys\HttpStatus;
use Aerys\Internal;
use Aerys\Internal\HttpDriver;
use Aerys\Logger;
use Aerys\Options;
use Aerys\Request;
use Aerys\Responder;
use Aerys\Response;
use Aerys\Server;
use Aerys\TryResponder;
use Amp\ByteStream\InMemoryStream;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Socket;
use Amp\Uri\Uri;

// @TODO test communication on half-closed streams (both ways) [also with yield message] (also with HTTP/1 pipelining...)

class ServerTest extends TestCase {
    public function tryRequest(array $emit, $responder, $middlewares = []) {
        if (!$responder instanceof Responder) {
            $responder = new CallableResponder($responder);
        }

        $gen = $this->tryIterativeRequest($responder, $middlewares);
        foreach ($emit as $part) {
            $gen->send($part);
        }
        return $gen->current();
    }


    public function tryIterativeRequest(Responder $responder, $middlewares = []) {
        $driver = new class($this) implements HttpDriver {
            private $test;
            private $emitters;
            public $response;
            public $body;
            private $client;

            public function __construct($test) {
                $this->test = $test;
                $this->client = new Internal\Client;
                $this->client->serverPort = 80;
                $this->client->httpDriver = $this;
            }

            public function setup(array $parseEmitters, callable $write) {
                $this->emitters = $parseEmitters;
            }

            public function writer(Internal\ServerRequest $ireq, Response $response): \Generator {
                $this->test->assertSame($this->client, $ireq->client);

                $this->response = $response;
                $this->body = "";
                do {
                    $this->body .= $part = yield;
                } while ($part !== null);
            }

            public function upgradeBodySize(Internal\ServerRequest $ireq) {
            }

            public function parser(Internal\Client $client): \Generator {
                $this->test->fail("We shouldn't be invoked the parser with no actual clients");
            }

            public function emit($emit) {
                $type = array_shift($emit);
                foreach ($this->emitters as $key => $emitter) {
                    if ($key & $type) {
                        if ($emit[0] instanceof Internal\ServerRequest) {
                            $emit[0]->client = $this->client;
                            $emitter(...$emit);
                        } else {
                            $emitter($this->client, ...$emit);
                        }
                    }
                }
            }
        };

        $host = new Host($driver);
        $host->use($responder);

        foreach ($middlewares as $middleware) {
            $host->use($middleware);
        }

        $logger = new class extends Logger {
            protected function output(string $message) { /* /dev/null */
            }
        };

        $options = new Options;
        $options->debug = true;

        $server = new Server($host, $options, $logger);
        $part = yield;
        while (1) {
            $driver->emit($part);
            $part = yield [&$driver->response, &$driver->body];
        }
    }

    public function newIreq() {
        $ireq = new Internal\ServerRequest;
        $ireq->streamId = 2;
        $ireq->trace = [["host", "localhost"]];
        $ireq->protocol = "2.0";
        $ireq->method = "GET";
        $ireq->uri = new Uri("http://localhost:80/foo");
        $ireq->headers = ["host" => ["localhost"]];
        return $ireq;
    }

    public function testBasicRequest() {
        $ireq = $this->newIreq();

        /** @var \Aerys\Response $response */
        list($response, $body) = $this->tryRequest([[HttpDriver::RESULT, $ireq]], function (Request $req) {
            $this->assertSame("localhost", $req->getHeader("Host"));
            $this->assertSame("/foo", $req->getUri()->getPath());
            $this->assertSame("GET", $req->getMethod());
            $this->assertSame("", yield $req->getBody()->buffer());

            return new Response(new InMemoryStream("message"), ["FOO" => "bar"]);
        });

        $this->assertSame(200, $response->getStatus());
        $this->assertSame("OK", $response->getReason());
        $this->assertSame("bar", $response->getHeader("foo"));

        $this->assertSame("message", $body);
    }

    public function testStreamRequest() {
        $ireq = $this->newIreq();

        /** @var \Aerys\Response $response */
        list($response, $body) = $this->tryRequest([
            [HttpDriver::ENTITY_HEADERS, $ireq],
            [HttpDriver::ENTITY_PART, "fooBar", 2],
            [HttpDriver::ENTITY_PART, "BUZZ!", 2],
            [HttpDriver::ENTITY_RESULT, 2],
        ], function (Request $req) {
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
     * @dataProvider providePreResponderHeaders
     */
    public function testPreResponderFailures(array $changes, int $status) {
        $ireq = $this->newIreq();
        foreach ($changes as $key => $change) {
            $ireq->$key = $change;
        }

        /** @var \Aerys\Response $response */
        list($response) = $this->tryRequest([[HttpDriver::RESULT, $ireq]], function (Request $req) {
            $this->fail("We should already have failed and never invoke the responder...");
        });

        $this->assertEquals($status, $response->getStatus());
    }

    public function providePreResponderHeaders() {
        return [
            [["method" => "OPTIONS", "target" => "*"], HttpStatus::NO_CONTENT],
            [["method" => "NOT_ALLOWED"], HttpStatus::METHOD_NOT_ALLOWED],
        ];
    }

    public function testOptionsRequest() {
        $ireq = $this->newIreq();
        $ireq->headers["host"] = "http://localhost";
        $ireq->uri = new Uri("http://localhost");
        $ireq->target = "*";
        $ireq->method = "OPTIONS";

        /** @var \Aerys\Response $response */
        list($response) = $this->tryRequest([[HttpDriver::RESULT, $ireq]], function (Request $req) {
            $this->fail("We should already have failed and never invoke the responder...");
        });

        $this->assertSame(HttpStatus::NO_CONTENT, $response->getStatus());
        $this->assertSame(implode(", ", (new Options)->allowedMethods), $response->getHeader("allow"));
    }

    public function testError() {
        $ireq = $this->newIreq();

        /** @var \Aerys\Response $response */
        list($response) = $this->tryRequest([[HttpDriver::RESULT, $ireq]], function (Request $req) {
            throw new \Exception;
        });

        $this->assertSame(HttpStatus::INTERNAL_SERVER_ERROR, $response->getStatus());
    }

    public function testNotFound() {
        $ireq = $this->newIreq();

        /** @var \Aerys\Response $response */
        list($response) = $this->tryRequest([[HttpDriver::RESULT, $ireq]], new TryResponder);

        $this->assertSame(HttpStatus::NOT_FOUND, $response->getStatus());
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

            public function writer(Internal\ServerRequest $ireq, Response $response): \Generator {
                $this->test->fail("We shouldn't be invoked the writer when not dispatching requests");
                yield;
            }

            public function parser(Internal\Client $client): \Generator {
                yield from ($this->parser)($client, $this->write);
            }

            public function upgradeBodySize(Internal\ServerRequest $ireq) {
            }
        };

        $host = new class($tls, $address, $this, $driver) extends Host {
            public function __construct($tls, $address, $test, $driver) {
                parent::__construct();
                $this->tls = $tls;
                $this->test = $test;
                $this->address = $address;
                $this->driver = $driver;
            }
            public function getBindableAddresses(): array {
                return [$this->address];
            }
            public function getTlsContext(): array {
                return $this->tls ? ["local_cert" => __DIR__."/server.pem", "crypto_method" => STREAM_CRYPTO_METHOD_SSLv23_SERVER] : [];
            }
            public function getHttpDriver(): HttpDriver {
                return $this->driver;
            }
            public function count(): int {
                return 1;
            }
        };

        $logger = new class extends Logger {
            protected function output(string $message) { /* /dev/null */
            }
        };

        $options = new Options;
        $options->debug = true;

        $server = new Server($host, $options, $logger);
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
