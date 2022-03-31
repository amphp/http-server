<?php

namespace Amp\Http\Server\Test;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Client\Body\StreamBody;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Status;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Queue;
use Amp\Socket;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\async;
use function Amp\delay;

class IntegrationTest extends AsyncTestCase
{
    private HttpClient $httpClient;

    private SocketHttpServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverSocket = Socket\listen("tcp://127.0.0.1:0");
        $this->httpClient = HttpClientBuilder::buildDefault();

        $serverFactory = $this->createMock(Socket\SocketServerFactory::class);
        $serverFactory->method('listen')
            ->willReturn($this->serverSocket);

        $driverFactory = new DefaultHttpDriverFactory($this->createMock(PsrLogger::class), $serverFactory);

        $this->server = new SocketHttpServer($this->createMock(PsrLogger::class), driverFactory: $driverFactory);
        $this->server->expose($this->serverSocket->getAddress());
    }

    private function getAuthority(): string
    {
        return "http://localhost:" . $this->serverSocket->getAddress()->getPort();
    }

    public function testNeverCallingExpose(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('No bind addresses specified');

        $server = new SocketHttpServer($this->createMock(PsrLogger::class));
        $server->start($this->createMock(RequestHandler::class));
    }

    public function testShutdownWaitsOnUnfinishedResponses(): void
    {
        $this->server->start(new ClosureRequestHandler(function () {
            delay(0.2);

            return new Response(Status::NO_CONTENT);
        }));

        $response = $this->httpClient->request(new ClientRequest($this->getAuthority() . "/"));

        // Ensure client already connected and sent request
        delay(0.1);

        $this->server->stop();

        self::assertSame(Status::NO_CONTENT, $response->getStatus());
    }

    public function testBasicRequest(): void
    {
        $this->server->start(new ClosureRequestHandler(function (Request $req) use (&$request, &$body) {
            $request = $req;
            $body = $request->getBody()->buffer();

            delay(0.2);

            return new Response(Status::OK, ["FOO" => "bar"], "message");
        }));

        $response = $this->httpClient->request(new ClientRequest($this->getAuthority() . "/foo"));

        self::assertStringStartsWith("localhost:", $request->getHeader("Host"));
        self::assertSame("/foo", $request->getUri()->getPath());
        self::assertSame("GET", $request->getMethod());
        self::assertSame("", $body);

        self::assertSame(Status::OK, $response->getStatus());
        self::assertSame('OK', $response->getReason());
        self::assertSame("bar", $response->getHeader("foo"));
        self::assertSame("message", $response->getBody()->buffer());
    }

    public function testStreamRequest(): void
    {
        $this->server->start(new ClosureRequestHandler(function (Request $req) use (&$request, &$body) {
            $request = $req;
            $body = $request->getBody()->buffer();

            delay(0.2);

            return new Response(Status::OK, ["FOO" => "bar"], "message");
        }));

        $queue = new Queue();

        async(static function () use ($queue) {
            delay(1);

            $queue->pushAsync("fooBar")->ignore();
            $queue->pushAsync("BUZZ!")->ignore();
            $queue->complete();
        });

        $response = $this->httpClient->request(new ClientRequest($this->getAuthority() . "/foo", 'POST', new StreamBody(
            new ReadableIterableStream($queue->pipe())
        )));

        self::assertStringStartsWith("localhost:", $request->getHeader("Host"));
        self::assertSame("/foo", $request->getUri()->getPath());
        self::assertSame("POST", $request->getMethod());
        self::assertSame("fooBarBUZZ!", $body);

        self::assertSame(Status::OK, $response->getStatus());
        self::assertSame('OK', $response->getReason());
        self::assertSame("bar", $response->getHeader("foo"));
        self::assertSame("message", $response->getBody()->buffer());
    }

    /**
     * @dataProvider providePreRequestHandlerRequests
     */
    public function testPreRequestHandlerFailure(ClientRequest $request, int $status): void
    {
        $this->server->start(new ClosureRequestHandler($this->createCallback(0)));

        $request->setUri($this->getAuthority());

        $response = $this->httpClient->request($request);

        self::assertSame($status, $response->getStatus());
    }

    public function providePreRequestHandlerRequests(): iterable
    {
        yield "TRACE" => [
            new ClientRequest("http://localhost", "TRACE"),
            Status::METHOD_NOT_ALLOWED,
        ];

        yield "UNKNOWN" => [
            new ClientRequest("http://localhost", "UNKNOWN"),
            Status::NOT_IMPLEMENTED,
        ];
    }

    public function testError(): void
    {
        $this->server->start(new ClosureRequestHandler(function (Request $req) {
            throw new \Exception;
        }));

        $response = $this->httpClient->request(new ClientRequest($this->getAuthority() . "/foo"));

        self::assertSame(Status::INTERNAL_SERVER_ERROR, $response->getStatus());
    }
}
