<?php

namespace Aerys\Test;

use Aerys\Body;
use Aerys\CallableResponder;
use Aerys\Client;
use Aerys\DefaultErrorHandler;
use Aerys\ErrorHandler;
use Aerys\HttpDriver;
use Aerys\Logger;
use Aerys\Options;
use Aerys\Request;
use Aerys\Responder;
use Aerys\Response;
use Aerys\Server;
use Aerys\TimeoutCache;
use Amp\Artax\Cookie\ArrayCookieJar;
use Amp\Artax\Cookie\Cookie;
use Amp\Artax\DefaultClient;
use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\ByteStream\IteratorStream;
use Amp\Delayed;
use Amp\Emitter;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\Status;
use Amp\Loop;
use Amp\Socket;
use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ServerTlsContext;
use Amp\Success;
use Amp\Uri\Uri;
use PHPUnit\Framework\TestCase;
use function Amp\call;

class ClientTest extends TestCase {
    public function startServer(callable $handler) {
        if (!$server = @stream_socket_server("tcp://127.0.0.1:*", $errno, $errstr)) {
            $this->markTestSkipped("Couldn't get a free port from the local ephemeral port range");
        }
        $address = stream_socket_get_name($server, $wantPeer = false);
        fclose($server);
        $port = parse_url($address, PHP_URL_PORT);

        $handler = new CallableResponder($handler);

        $logger = $this->createMock(Logger::class);
        $options = (new Options)->withDebugMode(true);
        $server = new Server($handler, $options, $logger);
        $server->expose("*", $port);
        $server->encrypt((new ServerTlsContext)->withDefaultCertificate(new Certificate(__DIR__."/server.pem")));

        yield $server->start();
        return [$address, $server];
    }

    public function testTrivialHttpRequest() {
        Loop::run(function () {
            list($address, $server) = yield from $this->startServer(function (Request $req) {
                $this->assertEquals("GET", $req->getMethod());
                $this->assertEquals("/uri", $req->getUri()->getPath());
                $this->assertEquals(["foo" => ["bar"], "baz" => ["1", "2"]], $req->getUri()->getAllQueryParameters());
                $this->assertEquals(["header"], $req->getHeaderArray("custom"));
                $this->assertNotNull($req->getCookie("test"));
                $this->assertSame("value", $req->getCookie("test")->getValue());

                $data = \str_repeat("*", 100000);
                $stream = new InMemoryStream("data/" . $data . "/data");

                $res = new Response($stream);

                $res->setCookie(new ResponseCookie("cookie", "with-value"));
                $res->setHeader("custom", "header");

                return $res;
            });

            $cookies = new ArrayCookieJar;
            $cookies->store(new Cookie("test", "value", null, "/", "localhost"));
            $context = (new ClientTlsContext)->withoutPeerVerification();
            $client = new DefaultClient($cookies, null, $context);
            $port = parse_url($address, PHP_URL_PORT);
            $promise = $client->request(
                (new \Amp\Artax\Request("https://localhost:$port/uri?foo=bar&baz=1&baz=2", "GET"))->withHeader("custom", "header")
            );

            /** @var Response $res */
            $res = yield $promise;
            $this->assertEquals(200, $res->getStatus());
            $this->assertEquals(["header"], $res->getHeaderArray("custom"));
            $body = yield $res->getBody();
            $this->assertEquals("data/" . str_repeat("*", 100000) . "/data", $body);
            $this->assertEquals("with-value", $cookies->get("localhost", "/", "cookie")[0]->getValue());

            Loop::stop();
        });
    }

    public function testClientDisconnect() {
        Loop::run(function () {
            list($address, $server) = yield from $this->startServer(function (Request $req) use (&$server) {
                $this->assertEquals("POST", $req->getMethod());
                $this->assertEquals("/", $req->getUri()->getPath());
                $this->assertEquals([], $req->getAllParams());
                $this->assertEquals("body", yield $req->getBody()->buffer());

                $data = "data";
                $data .= \str_repeat("_", $server->getOptions()->getOutputBufferSize() + 1);

                return new Response(new InMemoryStream($data));
            });

            $port = parse_url($address, PHP_URL_PORT);
            $context = (new ClientTlsContext)->withoutPeerVerification();
            $socket = yield Socket\cryptoConnect("tcp://localhost:$port/", null, $context);

            $request = "POST / HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\nContent-Length: 4\r\n\r\nbody";
            yield $socket->write($request);

            $socket->close();

            Loop::delay(100, function () use ($socket) {
                Loop::stop();
            });
        });
    }

    public function tryRequest(Request $request, callable $responder) {
        $driver = $this->createMock(HttpDriver::class);

        $driver->expects($this->once())
            ->method("setup")
            ->willReturnCallback(function (Client $client, callable $emitter) use (&$emit) {
                $emit = $emitter;
                yield;
            });

        $driver->method("writer")
            ->willReturnCallback(function (Response $written) use (&$response, &$body) {
                $response = $written;
                $body = "";
                do {
                    $body .= $part = yield true;
                } while ($part !== null);
            });

        $options = (new Options)
            ->withDebugMode(true);

        $client = new Client(
            \fopen("php://memory", "w"),
            new CallableResponder($responder),
            new DefaultErrorHandler,
            $this->createMock(Logger::class),
            $options,
            $this->createMock(TimeoutCache::class)
        );

        $client->start($driver);

        $emit($request);

        return [$response, $body];
    }

    public function testBasicRequest() {
        $request = new Request(
            $this->createMock(Client::class),
            "GET", // method
            new Uri("http://localhost:80/foo"), // URI
            ["host" => ["localhost"]] // headers
        );

        /** @var \Aerys\Response $response */
        list($response, $body) = $this->tryRequest($request, function (Request $req) {
            $this->assertSame("localhost", $req->getHeader("Host"));
            $this->assertSame("/foo", $req->getUri()->getPath());
            $this->assertSame("GET", $req->getMethod());
            $this->assertSame("", yield $req->getBody()->buffer());

            return new Response(new InMemoryStream("message"), ["FOO" => "bar"]);
        });

        $this->assertInstanceOf(Response::class, $response);

        $status = Status::OK;
        $this->assertSame($status, $response->getStatus());
        $this->assertSame(Status::getReason($status), $response->getReason());
        $this->assertSame("bar", $response->getHeader("foo"));

        $this->assertSame("message", $body);
    }

    public function testStreamRequest() {
        $emitter = new Emitter;

        $request = new Request(
            $this->createMock(Client::class),
            "GET", // method
            new Uri("http://localhost:80/foo"), // URI
            ["host" => ["localhost"]], // headers
            new Body(new IteratorStream($emitter->iterate())) // body
        );

        $emitter->emit("fooBar");
        $emitter->emit("BUZZ!");
        $emitter->complete();

        /** @var \Aerys\Response $response */
        list($response, $body) = $this->tryRequest($request, function (Request $req) {
            $buffer = "";
            while ((null !== $chunk = yield $req->getBody()->read())) {
                $buffer .= $chunk;
            }
            return new Response(new InMemoryStream($buffer));
        });

        $this->assertInstanceOf(Response::class, $response);

        $status = Status::OK;
        $this->assertSame($status, $response->getStatus());
        $this->assertSame(Status::getReason($status), $response->getReason());

        $this->assertSame("fooBarBUZZ!", $body);
    }

    /**
     * @dataProvider providePreResponderRequests
     */
    public function testPreResponderFailures(Request $request, int $status) {
        /** @var \Aerys\Response $response */
        list($response) = $this->tryRequest($request, function (Request $req) {
            $this->fail("We should already have failed and never invoke the responder...");
        });

        $this->assertInstanceOf(Response::class, $response);

        $this->assertEquals($status, $response->getStatus());
    }

    public function providePreResponderRequests() {
        return [
            [
                new Request(
                    $this->createMock(Client::class),
                    "OPTIONS", // method
                    new Uri("http://localhost:80"), // URI
                    ["host" => ["localhost"]], // headers
                    null // body
                ),
                Status::NO_CONTENT
            ],
            [
                new Request(
                    $this->createMock(Client::class),
                    "TRACE", // method
                    new Uri("http://localhost:80/"), // URI
                    ["host" => ["localhost"]] // headers
                ),
                Status::METHOD_NOT_ALLOWED
            ],
            [
                new Request(
                    $this->createMock(Client::class),
                    "UNKNOWN", // method
                    new Uri("http://localhost:80/"), // URI
                    ["host" => ["localhost"]] // headers
                ),
                Status::NOT_IMPLEMENTED
            ],
        ];
    }

    public function testOptionsRequest() {
        $request = new Request(
            $this->createMock(Client::class),
            "OPTIONS", // method
            new Uri("http://localhost:80"), // URI
            ["host" => ["localhost"]], // headers
            null // body
        );

        /** @var \Aerys\Response $response */
        list($response) = $this->tryRequest($request, function (Request $req) {
            $this->fail("We should already have failed and never invoke the responder...");
        });

        $this->assertSame(Status::NO_CONTENT, $response->getStatus());
        $this->assertSame(implode(", ", (new Options)->getAllowedMethods()), $response->getHeader("allow"));
    }

    public function testError() {
        $request = new Request(
            $this->createMock(Client::class),
            "GET", // method
            new Uri("http://localhost:80/foo"), // URI
            ["host" => ["localhost"]] // headers
        );

        /** @var \Aerys\Response $response */
        list($response) = $this->tryRequest($request, function (Request $req) {
            throw new \Exception;
        });

        $this->assertSame(Status::INTERNAL_SERVER_ERROR, $response->getStatus());
    }

    public function testWriterReturningEndsReadingResponse() {
        $driver = $this->createMock(HttpDriver::class);

        $driver->expects($this->once())
            ->method("setup")
            ->willReturnCallback(function (Client $client, callable $emitter) use (&$emit) {
                $emit = $emitter;
                yield;
            });

        $driver->method("writer")
            ->willReturnCallback(function (Response $written) use (&$body) {
                $count = 3;
                $body = "";
                do {
                    $body .= $part = yield;
                } while ($part !== null && --$count); // Return to end reading stream.
            });

        $bodyData = "{data}";

        $options = (new Options)
            ->withDebugMode(true);

        $body = $this->createMock(InputStream::class);
        $body->expects($this->exactly(3))
            ->method("read")
            ->willReturn(new Success($bodyData));

        $response = $this->createMock(Response::class);
        $response->method("getBody")
            ->willReturn($body);

        $responder = $this->createMock(Responder::class);
        $responder->expects($this->once())
            ->method("respond")
            ->willReturn(new Success($response));

        $client = new Client(
            \fopen("php://memory", "w"),
            $responder,
            new DefaultErrorHandler,
            $this->createMock(Logger::class),
            $options,
            $this->createMock(TimeoutCache::class)
        );

        $client->start($driver);

        $emit(new Request($client, "GET", new Uri("/")));

        $this->assertSame(str_repeat($bodyData, 3), $body);
    }

    public function startClient(callable $parser, $socket) {
        $driver = $this->createMock(HttpDriver::class);

        $driver->method("setup")
            ->willReturnCallback(function (Client $client, callable $onMessage, callable $writer) use ($parser) {
                yield from $parser($writer);
            });

        $options = (new Options)
            ->withDebugMode(true);

        $client = new Client(
            $socket,
            $this->createMock(Responder::class),
            $this->createMock(ErrorHandler::class),
            $this->createMock(Logger::class),
            $options,
            $this->createMock(TimeoutCache::class)
        );

        $client->start($driver);

        return $client;
    }

    public function provideFalseTrueUnixDomainSocket() {
        return [
            "tcp-unencrypted" => [false, false],
            //"tcp-encrypted" => [false, true],
            "unix" => [true, false],
        ];
    }

    /**
     * @dataProvider provideFalseTrueUnixDomainSocket
     */
    public function testIO(bool $unixSocket, bool $tls) {
        $tlsContext = null;

        if ($tls) {
            $tlsContext = new Socket\ServerTlsContext;
            $tlsContext = $tlsContext->withDefaultCertificate(new Socket\Certificate(__DIR__ . "/server.pem"));
        }

        if ($unixSocket) {
            $uri = tempnam(sys_get_temp_dir(), "aerys.") . ".sock";
            $uri = "unix://" . $uri;
            $server = Socket\listen($uri, null, $tlsContext);
        } else {
            $server = Socket\listen("tcp://127.0.0.1:0", null, $tlsContext);
            $uri = $server->getAddress();
        }

        Loop::run(function () use ($server, $uri, $tls) {
            $promise = call(function () use ($server, $tls) {
                /** @var \Amp\Socket\Socket $socket */
                $socket = yield $server->accept();

                if ($tls) {
                    yield $socket->enableCrypto();
                }

                yield $socket->write("a");

                // give readWatcher a chance
                yield new Delayed(10);

                yield $socket->write("b");

                stream_socket_shutdown($socket->getResource(), STREAM_SHUT_WR);
                $this->assertEquals("cd", yield $socket->read());
            });

            if ($tls) {
                $tlsContext = (new Socket\ClientTlsContext)->withoutPeerVerification();
                $tlsContext = $tlsContext->toStreamContextArray();
            } else {
                $tlsContext = [];
            }

            $client = \stream_socket_client($uri, $errno, $errstr, 1, STREAM_CLIENT_CONNECT, stream_context_create($tlsContext));

            $client = $this->startClient(function (callable $write) {
                $this->assertEquals("a", yield);
                $this->assertEquals("b", yield);
                $write("c");
                $write("d");
            }, $client);

            yield $promise;

            $client->close();

            Loop::stop();
        });
    }
}
