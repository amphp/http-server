<?php declare(strict_types=1);

namespace Amp\Http\Server\Test;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Client\Body\StreamBody;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Queue;
use Amp\Socket;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\async;
use function Amp\delay;

class IntegrationTest extends AsyncTestCase
{
    private HttpClient $httpClient;

    private SocketHttpServer $httpServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient = HttpClientBuilder::buildDefault();

        $this->httpServer = new SocketHttpServer($this->createMock(PsrLogger::class));
        $this->httpServer->expose(new Socket\InternetAddress('127.0.0.1', 0));
    }

    private function getAuthority(): string
    {
        $socketServer = $this->httpServer->getServers()[0] ?? self::fail('No servers created by HTTP server');
        return "http://" . $socketServer->getAddress()->toString();
    }

    public function testNeverCallingExpose(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('No bind addresses specified');

        $server = new SocketHttpServer($this->createMock(PsrLogger::class));
        $server->start($this->createMock(RequestHandler::class), $this->createMock(ErrorHandler::class));
    }

    public function testShutdownWaitsOnUnfinishedResponses(): void
    {
        $this->httpServer->start(new ClosureRequestHandler(function () {
            delay(0.2);

            return new Response(HttpStatus::NO_CONTENT);
        }), $this->createMock(ErrorHandler::class));

        $response = $this->httpClient->request(new ClientRequest($this->getAuthority() . "/"));

        // Ensure client already connected and sent request
        delay(0.1);

        $this->httpServer->stop();

        self::assertSame(HttpStatus::NO_CONTENT, $response->getStatus());
    }

    public function testBasicRequest(): void
    {
        $this->httpServer->start(new ClosureRequestHandler(function (Request $req) use (&$request, &$body) {
            $request = $req;
            $body = $request->getBody()->buffer();

            delay(0.2);

            return new Response(HttpStatus::OK, ["FOO" => "bar"], "message");
        }), $this->createMock(ErrorHandler::class));

        $response = $this->httpClient->request(new ClientRequest($this->getAuthority() . "/foo"));

        self::assertStringContainsString($request->getHeader("Host"), $this->getAuthority());
        self::assertSame("/foo", $request->getUri()->getPath());
        self::assertSame("GET", $request->getMethod());
        self::assertSame("", $body);

        self::assertSame(HttpStatus::OK, $response->getStatus());
        self::assertSame('OK', $response->getReason());
        self::assertSame("bar", $response->getHeader("foo"));
        self::assertSame("message", $response->getBody()->buffer());
    }

    public function testStreamRequest(): void
    {
        $this->httpServer->start(new ClosureRequestHandler(function (Request $req) use (&$request, &$body) {
            $request = $req;
            $body = $request->getBody()->buffer();

            delay(0.2);

            return new Response(HttpStatus::OK, ["FOO" => "bar"], "message");
        }), $this->createMock(ErrorHandler::class));

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

        self::assertStringContainsString($request->getHeader("Host"), $this->getAuthority());
        self::assertSame("/foo", $request->getUri()->getPath());
        self::assertSame("POST", $request->getMethod());
        self::assertSame("fooBarBUZZ!", $body);

        self::assertSame(HttpStatus::OK, $response->getStatus());
        self::assertSame('OK', $response->getReason());
        self::assertSame("bar", $response->getHeader("foo"));
        self::assertSame("message", $response->getBody()->buffer());
    }

    /**
     * @dataProvider providePreRequestHandlerRequests
     */
    public function testPreRequestHandlerFailure(ClientRequest $request, int $status): void
    {
        $this->httpServer->start(new ClosureRequestHandler($this->createCallback(0)), new DefaultErrorHandler());

        $request->setUri($this->getAuthority());

        $response = $this->httpClient->request($request);

        self::assertSame($status, $response->getStatus());
    }

    public function providePreRequestHandlerRequests(): iterable
    {
        yield "TRACE" => [
            new ClientRequest("http://localhost", "TRACE"),
            HttpStatus::METHOD_NOT_ALLOWED,
        ];

        yield "UNKNOWN" => [
            new ClientRequest("http://localhost", "UNKNOWN"),
            HttpStatus::NOT_IMPLEMENTED,
        ];
    }

    public function testError(): void
    {
        $this->httpServer->start(new ClosureRequestHandler(function (Request $req) {
            throw new \Exception;
        }), new DefaultErrorHandler());

        $response = $this->httpClient->request(new ClientRequest($this->getAuthority() . "/foo"));

        self::assertSame(HttpStatus::INTERNAL_SERVER_ERROR, $response->getStatus());
    }
}
