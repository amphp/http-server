<?php

namespace Amp\Http\Server\Test\Driver;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Driver\HttpDriver;
use Amp\Http\Server\Driver\HttpDriverFactory;
use Amp\Http\Server\Driver\RemoteClient;
use Amp\Http\Server\Driver\TimeoutCache;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestBody;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Queue;
use Amp\Socket;
use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\ServerTlsContext;
use League\Uri;
use League\Uri\Components\Query;
use Psr\Log\LoggerInterface as PsrLogger;
use const Amp\Process\IS_WINDOWS;
use function Amp\async;
use function Amp\delay;

class RemoteClientTest extends AsyncTestCase
{
    public function startServer(callable $handler): array
    {
        if (!$server = @\stream_socket_server("tcp://127.0.0.1:*", $errno, $errstr)) {
            self::markTestSkipped("Couldn't get a free port from the local ephemeral port range");
        }

        $address = \stream_socket_get_name($server, $wantPeer = false);
        \fclose($server);

        $handler = new ClosureRequestHandler($handler);
        $tlsContext = (new ServerTlsContext)->withDefaultCertificate(new Certificate(\dirname(__DIR__) . "/server.pem"));

        $servers = [
            Socket\listen(
                $address,
                (new Socket\BindContext())->withTlsContext($tlsContext)
            ),
        ];

        $options = (new Options)->withDebugMode();
        $server = new HttpServer($servers, $handler, $this->createMock(PsrLogger::class), $options);

        $server->start();
        return [$address, $server];
    }

    public function testTrivialHttpRequest(): void
    {
        [$address, $server] = $this->startServer(function (Request $req) {
            $this->assertEquals("GET", $req->getMethod());
            $this->assertEquals("/uri", $req->getUri()->getPath());
            $query = Query::createFromUri($req->getUri());
            $this->assertEquals(
                [["foo", "bar"], ["baz", "1"], ["baz", "2"]],
                \iterator_to_array($query->getIterator())
            );
            $this->assertEquals(["header"], $req->getHeaderArray("custom"));

            $data = \str_repeat("*", 100000);
            $stream = new ReadableBuffer("data/" . $data . "/data");

            $res = new Response(Status::OK, [], $stream);

            $res->setCookie(new ResponseCookie("cookie", "with-value"));
            $res->setHeader("custom", "header");

            return $res;
        });

        $connector = new class implements Socket\SocketConnector {
            public function connect(
                string $uri,
                ?ConnectContext $context = null,
                ?Cancellation $token = null
            ): Socket\EncryptableSocket {
                $context = (new Socket\ConnectContext)
                    ->withTlsContext((new ClientTlsContext(''))->withoutPeerVerification());

                return (new Socket\DnsSocketConnector())->connect($uri, $context, $token);
            }
        };

        $client = (new HttpClientBuilder)
            ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory($connector)))
            ->build();

        $port = \parse_url($address, PHP_URL_PORT);
        $request = new ClientRequest("https://localhost:$port/uri?foo=bar&baz=1&baz=2", "GET");
        $request->setHeader("custom", "header");

        $response = $client->request($request);;
        self::assertEquals(200, $response->getStatus());
        self::assertEquals(["header"], $response->getHeaderArray("custom"));
        $body = $response->getBody()->buffer();
        self::assertEquals("data/" . \str_repeat("*", 100000) . "/data", $body);

        $server->stop();
    }

    public function testClientDisconnect(): void
    {
        [$address, $server] = $this->startServer(function (Request $req) use (&$server) {
            $this->assertEquals("POST", $req->getMethod());
            $this->assertEquals("/", $req->getUri()->getPath());
            $this->assertEquals([], $req->getAttributes());
            $this->assertEquals("body", $req->getBody()->buffer());

            $data = "data";
            $data .= \str_repeat("_", $server->getOptions()->getOutputBufferSize() + 1);

            return new Response(Status::OK, [], $data);
        });

        $port = \parse_url($address, PHP_URL_PORT);
        $context = (new Socket\ConnectContext)
            ->withTlsContext((new ClientTlsContext(''))->withoutPeerVerification());

        $socket = Socket\connect("tcp://localhost:$port/", $context);
        $socket->setupTls();

        $request = "POST / HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\nContent-Length: 4\r\n\r\nbody";
        $socket->write($request);

        $socket->close();

        delay(0.1);

        $server->stop();
    }

    public function testBasicRequest(): void
    {
        $request = new Request(
            $this->createMock(Client::class),
            "GET", // method
            Uri\Http::createFromString("http://localhost:80/foo"), // URI
            ["host" => ["localhost"]] // headers
        );

        /** @var Response $response */
        [$response, $body] = $this->tryRequest($request, function (Request $req) {
            $this->assertSame("localhost", $req->getHeader("Host"));
            $this->assertSame("/foo", $req->getUri()->getPath());
            $this->assertSame("GET", $req->getMethod());
            $this->assertSame("", $req->getBody()->buffer());

            return new Response(Status::OK, ["FOO" => "bar"], "message");
        });

        self::assertInstanceOf(Response::class, $response);

        $status = Status::OK;
        self::assertSame($status, $response->getStatus());
        self::assertSame(Status::getReason($status), $response->getReason());
        self::assertSame("bar", $response->getHeader("foo"));

        self::assertSame("message", $body);
    }

    public function testStreamRequest(): void
    {
        $queue = new Queue();

        $request = new Request(
            $this->createMock(Client::class),
            "GET", // method
            Uri\Http::createFromString("http://localhost:80/foo"), // URI
            ["host" => ["localhost"]], // headers
            new RequestBody(new ReadableIterableStream($queue->pipe())) // body
        );

        $queue->pushAsync("fooBar")->ignore();
        $queue->pushAsync("BUZZ!")->ignore();
        $queue->complete();

        /** @var Response $response */
        [$response, $body] = $this->tryRequest($request, function (Request $req) {
            $buffer = "";
            while (null !== $chunk = $req->getBody()->read()) {
                $buffer .= $chunk;
            }
            return new Response(Status::OK, [], $buffer);
        });

        self::assertInstanceOf(Response::class, $response);

        $status = Status::OK;
        self::assertSame($status, $response->getStatus());
        self::assertSame(Status::getReason($status), $response->getReason());

        self::assertSame("fooBarBUZZ!", $body);
    }

    /**
     * @dataProvider providePreRequestHandlerRequests
     */
    public function testPreRequestHandlerFailure(Request $request, int $status): void
    {
        /** @var Response $response */
        [$response] = $this->tryRequest($request, function (Request $req) {
            $this->fail("We should already have failed and never invoke the request handler…");
        });

        self::assertInstanceOf(Response::class, $response);

        self::assertEquals($status, $response->getStatus());
    }

    public function providePreRequestHandlerRequests(): array
    {
        return [
            [
                new Request(
                    $this->createMock(Client::class),
                    "OPTIONS", // method
                    Uri\Http::createFromString("http://localhost:80"), // URI
                    ["host" => ["localhost"]], // headers
                    '' // body
                ),
                Status::NO_CONTENT,
            ],
            [
                new Request(
                    $this->createMock(Client::class),
                    "TRACE", // method
                    Uri\Http::createFromString("http://localhost:80/"), // URI
                    ["host" => ["localhost"]] // headers
                ),
                Status::METHOD_NOT_ALLOWED,
            ],
            [
                new Request(
                    $this->createMock(Client::class),
                    "UNKNOWN", // method
                    Uri\Http::createFromString("http://localhost:80/"), // URI
                    ["host" => ["localhost"]] // headers
                ),
                Status::NOT_IMPLEMENTED,
            ],
        ];
    }

    public function testOptionsRequest(): void
    {
        $request = new Request(
            $this->createMock(Client::class),
            "OPTIONS", // method
            Uri\Http::createFromString("http://localhost:80"), // URI
            ["host" => ["localhost"]], // headers
            '' // body
        );

        /** @var Response $response */
        [$response] = $this->tryRequest($request, function (Request $req) {
            $this->fail("We should already have failed and never invoke the request handler…");
        });

        self::assertSame(Status::NO_CONTENT, $response->getStatus());
        self::assertSame(\implode(", ", (new Options)->getAllowedMethods()), $response->getHeader("allow"));
    }

    public function testError(): void
    {
        $request = new Request(
            $this->createMock(Client::class),
            "GET", // method
            Uri\Http::createFromString("http://localhost:80/foo"), // URI
            ["host" => ["localhost"]] // headers
        );

        /** @var Response $response */
        [$response] = $this->tryRequest($request, function (Request $req) {
            throw new \Exception;
        });

        self::assertSame(Status::INTERNAL_SERVER_ERROR, $response->getStatus());
    }

    public function testWriterReturningEndsReadingResponse(): void
    {
        $driver = $this->createMock(HttpDriver::class);

        $driver->expects(self::once())
            ->method("setup")
            ->willReturnCallback(function (Client $client, callable $queue) use (&$emit) {
                $emit = $queue;
                yield;
            });

        $bodyWritten = "";
        $driver->method("write")
            ->willReturnCallback(function (Request $request, Response $written) use (&$bodyWritten) {
                $count = 3;
                while ($count-- && null !== $part = $written->getBody()->read()) {
                    $bodyWritten .= $part;
                }
            });

        $factory = $this->createMock(HttpDriverFactory::class);
        $factory->method('selectDriver')
            ->willReturn($driver);

        $bodyData = "{data}";

        $options = (new Options)
            ->withDebugMode();

        $body = $this->createMock(ReadableStream::class);
        $body->expects(self::exactly(3))
            ->method("read")
            ->willReturn($bodyData);

        $response = new Response(Status::OK, [], $body);

        $requestHandler = $this->createMock(RequestHandler::class);
        $requestHandler->expects(self::once())
            ->method("handleRequest")
            ->willReturn($response);

        [$server, $client] = Socket\createSocketPair();

        $client = new RemoteClient(
            $client,
            $requestHandler,
            new DefaultErrorHandler,
            $this->createMock(PsrLogger::class),
            $options,
            new TimeoutCache
        );

        $client->start($factory);

        delay(0.1); // Tick event loop a few times to resolve promises.

        $emit(new Request($client, "GET", Uri\Http::createFromString("/")));

        $client->stop(0.1);

        self::assertSame(\str_repeat($bodyData, 3), $bodyWritten);
    }

    public function provideFalseTrueUnixDomainSocket(): array
    {
        return [
            "tcp-unencrypted" => [false, false],
            //"tcp-encrypted" => [false, true],
            "unix" => [true, false],
        ];
    }

    /**
     * @dataProvider provideFalseTrueUnixDomainSocket
     */
    public function testIO(bool $unixSocket, bool $tls): void
    {
        if (IS_WINDOWS && $unixSocket) {
            self::markTestSkipped('Unix sockets are not supported on Windows');
        }

        $bindContext = null;

        if ($tls) {
            $tlsContext = (new Socket\ServerTlsContext)
                ->withDefaultCertificate(new Socket\Certificate(\dirname(__DIR__) . "/server.pem"));
            $bindContext = (new Socket\BindContext)->withTlsContext($tlsContext);
        }

        if ($unixSocket) {
            $uri = \tempnam(\sys_get_temp_dir(), "aerys.") . ".sock";
            $uri = "unix://" . $uri;
            $server = Socket\listen($uri, $bindContext);
        } else {
            $server = Socket\listen("tcp://127.0.0.1:0", $bindContext);
            $uri = $server->getAddress();
        }

        $future = async(function () use ($server, $tls) {
            $socket = $server->accept();

            \assert($socket !== null);

            if ($tls) {
                $socket->setupTls();
            }

            $socket->write("a");

            // give readWatcher a chance
            delay(0.1);

            $socket->write("b");

            \stream_socket_shutdown($socket->getResource(), STREAM_SHUT_WR);
            $this->assertEquals("cd", $socket->read());
        });

        if ($tls) {
            $tlsContext = (new Socket\ClientTlsContext(''))->withoutPeerVerification();
            $tlsContext = $tlsContext->toStreamContextArray();
        } else {
            $tlsContext = [];
        }

        $client = \stream_socket_client(
            $uri,
            $errno,
            $errstr,
            1,
            STREAM_CLIENT_CONNECT,
            \stream_context_create($tlsContext)
        );

        $client = $this->startClient(function (callable $write) {
            $this->assertEquals("a", yield);
            $this->assertEquals("b", yield);
            $write("c");
            $write("d");
        }, $client);

        $future->await();

        $client->stop(0);
    }

    protected function tryRequest(Request $request, callable $requestHandler): array
    {
        $driver = $this->createMock(HttpDriver::class);

        $driver->expects(self::once())
            ->method("setup")
            ->willReturnCallback(static function (Client $client, callable $queue) use (&$emit) {
                $emit = $queue;
                yield;
            });

        $driver->method("write")
            ->willReturnCallback(function (Request $request, Response $written) use (&$response, &$body) {
                $response = $written;
                $body = "";
                while (null !== $part = $response->getBody()->read()) {
                    $body .= $part;
                }
            });

        $factory = $this->createMock(HttpDriverFactory::class);
        $factory->method('selectDriver')
            ->willReturn($driver);

        $options = (new Options)
            ->withDebugMode();

        [$server, $client] = Socket\createSocketPair();

        $client = new RemoteClient(
            $client,
            new ClosureRequestHandler($requestHandler),
            new DefaultErrorHandler,
            $this->createMock(PsrLogger::class),
            $options,
            new TimeoutCache
        );

        $client->start($factory);

        delay(0.1); // Tick event loop a few times to resolve promises.

        $emit($request);

        delay(0.1); // Tick event loop a few times to resolve promises.

        $client->stop(0.1);

        return [$response, $body];
    }

    protected function startClient(callable $parser, $socket): RemoteClient
    {
        $driver = $this->createMock(HttpDriver::class);

        $driver->method("setup")
            ->willReturnCallback(function (
                Client $client,
                callable $onMessage,
                callable $writer
            ) use ($parser) {
                yield from $parser($writer);
            });

        $factory = $this->createMock(HttpDriverFactory::class);
        $factory->method('selectDriver')
            ->willReturn($driver);

        $options = (new Options)
            ->withDebugMode();

        $client = new RemoteClient(
            Socket\ResourceSocket::fromServerSocket($socket),
            $this->createMock(RequestHandler::class),
            $this->createMock(ErrorHandler::class),
            $this->createMock(PsrLogger::class),
            $options,
            new TimeoutCache
        );

        $client->start($factory);

        delay(0.1); // Tick event loop a few times to resolve promises.

        return $client;
    }
}
