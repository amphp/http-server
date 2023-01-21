<?php declare(strict_types=1);

namespace Amp\Http\Server\Test\Driver;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\WritableBuffer;
use Amp\Future;
use Amp\Http\Client\Connection\Internal\Http1Parser;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Http2\Http2Parser;
use Amp\Http\HttpStatus;
use Amp\Http\Message;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\Http1Driver;
use Amp\Http\Server\Driver\Http2Driver;
use Amp\Http\Server\Driver\HttpDriver;
use Amp\Http\Server\Driver\UpgradedSocket;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Trailers;
use Amp\Pipeline\Queue;
use League\Uri;
use Psr\Log\NullLogger;
use function Amp\async;
use function Amp\delay;

class Http1DriverTest extends HttpDriverTest
{
    /**
     * @dataProvider provideUnparsableRequests
     */
    public function testBadRequestBufferedParse(
        string $unparsable,
        int $errCode,
        string $errMsg,
    ): void {
        $driver = new Http1Driver(
            new ClosureRequestHandler($this->createCallback(0)),
            new DefaultErrorHandler(), // Using concrete instance to generate error response.
            new NullLogger,
        );

        $client = $this->createClientMock();
        $input = new ReadableBuffer($unparsable);
        $output = new WritableBuffer();

        $driver->handleClient($client, $input, $output);

        self::assertStringStartsWith(\sprintf("HTTP/1.0 %d %s", $errCode, $errMsg), $output->buffer());
    }

    /**
     * @dataProvider provideUnparsableRequests
     */
    public function testBadRequestIncrementalParse(
        string $unparsable,
        int $errCode,
        string $errMsg,
    ): void {
        $driver = new Http1Driver(
            new ClosureRequestHandler($this->createCallback(0)),
            new DefaultErrorHandler(), // Using concrete instance to generate error response.
            new NullLogger,
        );

        $client = $this->createClientMock();
        $input = new ReadableIterableStream((static function () use ($unparsable) {
            for ($i = 0, $c = \strlen($unparsable); $i < $c; $i++) {
                yield $unparsable[$i];
            }
        })());

        $output = new WritableBuffer();

        $driver->handleClient($client, $input, $output);

        self::assertStringStartsWith(\sprintf("HTTP/1.0 %d %s", $errCode, $errMsg), $output->buffer());
    }

    /**
     * @dataProvider provideParsableRequests
     */
    public function testBufferedRequestParse(string $msg, array $expectations): void
    {
        $requestHandler = new ClosureRequestHandler(function (Request $req) use (&$request) {
            $request = $req;
        });

        $driver = new Http1Driver(
            $requestHandler,
            $this->createMock(ErrorHandler::class),
            new NullLogger,
        );

        $input = new ReadableBuffer($msg);
        $output = new WritableBuffer();

        async(fn () => $driver->handleClient(
            $this->createClientMock(),
            $input,
            $output,
        ));

        delay(0.1); // Allow parser generator to continue.

        self::assertInstanceOf(Request::class, $request);

        $body = $request->getBody()->buffer();

        self::assertSame($expectations["protocol"], $request->getProtocolVersion(), "protocol mismatch");
        self::assertSame($expectations["method"], $request->getMethod(), "method mismatch");
        self::assertSame($expectations["uri"], $request->getUri()->getPath(), "uri mismatch");
        self::assertSame($expectations["headers"], $request->getHeaders(), "headers mismatch");
        self::assertSame($expectations["raw-headers"], $request->getRawHeaders(), "raw headers mismatch");
        self::assertSame($expectations["body"], $body, "body mismatch");
    }

    /**
     * @dataProvider provideParsableRequests
     */
    public function testIncrementalRequestParse(string $msg, array $expectations): void
    {
        $requestHandler = new ClosureRequestHandler(function (Request $req) use (&$request) {
            $request = $req;
        });

        $driver = new Http1Driver(
            $requestHandler,
            $this->createMock(ErrorHandler::class),
            new NullLogger,
        );

        $input = new ReadableIterableStream((static function () use ($msg) {
            for ($i = 0, $c = \strlen($msg); $i < $c; $i++) {
                yield $msg[$i];
            }
        })());

        $output = new WritableBuffer();

        async(fn () => $driver->handleClient(
            $this->createClientMock(),
            $input,
            $output,
        ));

        delay(0.1); // Allow parser generator to continue.

        self::assertInstanceOf(Request::class, $request);

        $body = $request->getBody()->buffer();

        $defaultPort = $request->getUri()->getScheme() === "https" ? 443 : 80;
        self::assertSame($expectations["protocol"], $request->getProtocolVersion(), "protocol mismatch");
        self::assertSame($expectations["method"], $request->getMethod(), "method mismatch");
        self::assertSame($expectations["uri"], $request->getUri()->getPath(), "uri mismatch");
        self::assertSame($expectations["headers"], $request->getHeaders(), "headers mismatch");
        self::assertSame($expectations["body"], $body, "body mismatch");
        self::assertSame(80, $request->getUri()->getPort() ?: $defaultPort);
    }

    public function testOptionsAsteriskRequest(): void
    {
        $msg = "OPTIONS * HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $driver = new Http1Driver(
            new ClosureRequestHandler(function () {
                $this->fail("Should not be called");
            }),
            $this->createMock(ErrorHandler::class),
            new NullLogger,
            allowedMethods: ["OPTIONS", "HEAD", "GET", "FOO"],
        );

        $driver->handleClient(
            $this->createClientMock(),
            new ReadableBuffer($msg),
            $output = new WritableBuffer,
        );

        $output->close();
        self::assertStringStartsWith("HTTP/1.1 204 No Content\r\n", $output->buffer());
        self::assertStringContainsString("\r\nallow: OPTIONS, HEAD, GET, FOO\r\n", $output->buffer());
    }

    public function testIdentityBodyParseEmit(): void
    {
        $originalBody = "12345";
        $length = \strlen($originalBody);
        $msg =
            "POST /post-endpoint HTTP/1.0\r\n" .
            "Host: localhost\r\n" .
            "Cookie: cookie1=value1\r\n" .
            "Cookie: cookie2=value2\r\n" .
            "Content-Length: {$length}\r\n" .
            "\r\n" .
            $originalBody;

        $requestHandler = new ClosureRequestHandler(function (Request $req) use (&$request) {
            $request = $req;
        });

        $driver = new Http1Driver(
            $requestHandler,
            $this->createMock(ErrorHandler::class),
            new NullLogger,
        );

        $input = new ReadableIterableStream((static function () use ($msg) {
            for ($i = 0, $c = \strlen($msg); $i < $c; $i++) {
                yield $msg[$i];
            }
        })());

        $output = new WritableBuffer();

        async(fn () => $driver->handleClient(
            $this->createClientMock(),
            $input,
            $output,
        ));

        delay(0.1); // Allow parser generator to continue.

        self::assertInstanceOf(Request::class, $request);

        $body = $request->getBody()->buffer();

        self::assertSame($originalBody, $body);
    }

    /**
     * provide multiple chunk-sizes to test with.
     */
    public function chunkSizeProvider(): \Generator
    {
        for ($i = 1; $i < 11; $i++) {
            yield [$i];
        }
    }

    /**
     * @dataProvider chunkSizeProvider
     */
    public function testChunkedBodyParseEmit(int $chunkSize): void
    {
        $msg =
            "POST https://test.local:1337/post-endpoint HTTP/1.0\r\n" .
            "Host: test.local:1337\r\n" .
            "Transfer-Encoding: chunked\r\n" .
            "Cookie: cookie1=value1\r\n" .
            "Cookie: cookie2=value2\r\n" .
            "\r\n" .
            "5\r\n" .
            "woot!\r\n" .
            "4\r\n" .
            "test\r\n" .
            "0\r\n\r\n";

        $expectedBody = "woot!test";

        $requestHandler = new ClosureRequestHandler(function (Request $req) use (&$request) {
            $request = $req;
        });

        $driver = new Http1Driver(
            $requestHandler,
            $this->createMock(ErrorHandler::class),
            new NullLogger,
        );

        $input = new ReadableIterableStream((static function () use ($msg, $chunkSize) {
            foreach (\str_split($msg, $chunkSize) as $chunk) {
                yield $chunk;
            }
        })());

        $output = new WritableBuffer();

        async(fn () => $driver->handleClient(
            $this->createClientMock(),
            $input,
            $output,
        ));

        delay(0.1); // Allow parser generator to continue.

        self::assertInstanceOf(Request::class, $request);

        $body = $request->getBody()->buffer();

        self::assertSame($expectedBody, $body);
    }

    public function provideParsableRequests(): array
    {
        $return = [];

        // 0 --- basic request -------------------------------------------------------------------->

        $msg =
            "GET / HTTP/1.1" . "\r\n" .
            "Host: localhost" . "\r\n" .
            "\r\n";
        $trace = \substr($msg, 0, -2);
        $expectations = [
            "trace" => $trace,
            "protocol" => "1.1",
            "method" => "GET",
            "uri" => "/",
            "headers" => ["host" => ["localhost"]],
            "raw-headers" => [["Host", "localhost"]],
            "body" => "",
        ];

        $return[] = [$msg, $expectations];

        // 1 --- multi-headers -------------------------------------------------------------------->

        $msg =
            "POST /post-endpoint HTTP/1.0\r\n" .
            "Host: localhost:80\r\n" .
            "Cookie: cookie1\r\n" .
            "Cookie: cookie2\r\n" .
            "Content-Length: 3\r\n" .
            "\r\n" .
            "123";
        $trace = \explode("\r\n", $msg);
        \array_pop($trace);
        $trace = \implode("\r\n", $trace);

        $headers = [
            "host" => ["localhost:80"],
            "cookie" => ["cookie1", "cookie2"],
            "content-length" => ["3"],
        ];

        $rawHeaders = [
            ["Host", "localhost:80"],
            ["Cookie", "cookie1"],
            ["Cookie", "cookie2"],
            ["Content-Length", "3"],
        ];

        $expectations = [
            "trace" => $trace,
            "protocol" => "1.0",
            "method" => "POST",
            "uri" => "/post-endpoint",
            "headers" => $headers,
            "raw-headers" => $rawHeaders,
            "body" => "123",
        ];

        $return[] = [$msg, $expectations];

        // 2 --- OPTIONS request ------------------------------------------------------------------>

        $msg = "OPTIONS / HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $trace = \substr($msg, 0, -2);

        $expectations = [
            "trace" => $trace,
            "protocol" => "1.1",
            "method" => "OPTIONS",
            "uri" => "/",
            "headers" => ["host" => ["localhost"]],
            "raw-headers" => [["Host", "localhost"]],
            "body" => "",
        ];

        $return[] = [$msg, $expectations];

        // 3 --- real world headers --------------------------------------------------------------->

        $trace =
            "GET /test HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "Connection: keep-alive\r\n" .
            "User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.11 (KHTML, like Gecko) Chrome/23.0.1271.97 Safari/537.11\r\n" .
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n" .
            "Accept-Encoding: gzip,deflate,sdch\r\n" .
            "Accept-Language: en-US,en;q=0.8\r\n" .
            "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.3\r\n" .
            "Content-Length: 5\r\n";

        $msg = "{$trace}\r\n12345";

        $headers = [
            "host" => ["localhost"],
            "connection" => ["keep-alive"],
            "user-agent" => ["Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.11 (KHTML, like Gecko) Chrome/23.0.1271.97 Safari/537.11"],
            "accept" => ["text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"],
            "accept-encoding" => ["gzip,deflate,sdch"],
            "accept-language" => ["en-US,en;q=0.8"],
            "accept-charset" => ["ISO-8859-1,utf-8;q=0.7,*;q=0.3"],
            "content-length" => ["5"],
        ];

        $rawHeaders = [
            ["Host", "localhost"],
            ["Connection", "keep-alive"],
            [
                "User-Agent",
                "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.11 (KHTML, like Gecko) Chrome/23.0.1271.97 Safari/537.11",
            ],
            ["Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"],
            ["Accept-Encoding", "gzip,deflate,sdch"],
            ["Accept-Language", "en-US,en;q=0.8"],
            ["Accept-Charset", "ISO-8859-1,utf-8;q=0.7,*;q=0.3"],
            ["Content-Length", "5"],
        ];

        $expectations = [
            "trace" => $trace,
            "protocol" => "1.1",
            "method" => "GET",
            "uri" => "/test",
            "headers" => $headers,
            "raw-headers" => $rawHeaders,
            "body" => "12345",
        ];

        $return[] = [$msg, $expectations];

        // 4 --- HTTP/1.0 with header folding ---------------------------------------------------------->

        $trace =
            "GET /someurl.html HTTP/1.0\r\n" .
            "Host: \r\n" .
            " localhost\r\n" .
            "X-My-Header: 42\r\n" .
            "\r\n";

        $msg = $trace . "\r\n";

        $headers = [
            'host' => ['localhost'],
            'x-my-header' => ['42'],
        ];

        $rawHeaders = [
            ["Host", "localhost"],
            ["X-My-Header", "42"],
        ];

        $expectations = [
            "trace" => $trace,
            "protocol" => "1.0",
            "method" => "GET",
            "uri" => "/someurl.html",
            "headers" => $headers,
            "raw-headers" => $rawHeaders,
            "body" => "",
        ];

        $return[] = [$msg, $expectations];

        // 5 --- chunked entity body -------------------------------------------------------------->

        $trace =
            "GET /test HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "Transfer-Encoding: chunked\r\n";
        $msg = $trace .
            "\r\n" .
            "5\r\n" .
            "woot!\r\n" .
            "4\r\n" .
            "test\r\n" .
            "0\r\n\r\n";

        $headers = [
            "host" => ["localhost"],
            "transfer-encoding" => ["chunked"],
        ];

        $rawHeaders = [
            ["Host", "localhost"],
            ["Transfer-Encoding", "chunked"],
        ];

        $expectations = [
            "trace" => $trace,
            "protocol" => "1.1",
            "method" => "GET",
            "uri" => "/test",
            "headers" => $headers,
            "raw-headers" => $rawHeaders,
            "body" => "woot!test",
        ];

        $return[] = [$msg, $expectations];

        // 6 --- chunked entity body with trailer headers ----------------------------------------->

        $trace =
            "GET /test HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "Transfer-Encoding: chunked\r\n";
        $msg = $trace .
            "\r\n" .
            "5\r\n" .
            "woot!\r\n" .
            "4\r\n" .
            "test\r\n" .
            "0\r\n" .
            "My-Trailer: 42\r\n" .
            "\r\n";

        $headers = [
            "host" => ["localhost"],
            "transfer-encoding" => ["chunked"],
        ];

        $rawHeaders = [
            ["Host", "localhost"],
            ["Transfer-Encoding", "chunked"],
        ];

        $expectations = [
            "trace" => $trace,
            "protocol" => "1.1",
            "method" => "GET",
            "uri" => "/test",
            "headers" => $headers,
            "raw-headers" => $rawHeaders,
            "body" => "woot!test",
        ];

        $return[] = [$msg, $expectations];

        // x -------------------------------------------------------------------------------------->

        return $return;
    }

    public function provideUnparsableRequests(): array
    {
        $return = [];

        // 0 -------------------------------------------------------------------------------------->

        $msg = "dajfalkjf jslfhalsdjf\r\n\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: invalid request line";
        $return[] = [$msg, $errCode, $errMsg];

        // 1 -------------------------------------------------------------------------------------->

        $msg = "test   \r\n\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: invalid request line";
        $return[] = [$msg, $errCode, $errMsg];

        // 2 -------------------------------------------------------------------------------------->

        $msg =
            "GET /someurl.html HTTP/1.0\r\n" .
            "Host: localhost\r\n" .
            "X-My-Header: " . \str_repeat("x", 65536) . "r\n" .
            "\r\n";
        $errCode = 431;
        $errMsg = "Bad Request: header size violation";
        $return[] = [$msg, $errCode, $errMsg];

        // 3 -------------------------------------------------------------------------------------->

        $msg =
            "GET /someurl.html HTTP/1.1\r\n" .
            "Host: \r\n" .
            " localhost\r\n" .
            "X-My-Header: 42\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: Invalid header syntax: Obsolete line folding";
        $return[] = [$msg, $errCode, $errMsg];

        // 4 -------------------------------------------------------------------------------------->

        $msg =
            "GET /someurl.html HTTP/1.1\r\n" .
            "Host: \r\n\tlocalhost\r\n" .
            "X-My-Header: 42\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: Invalid header syntax: Obsolete line folding";
        $return[] = [$msg, $errCode, $errMsg];

        // 5 -------------------------------------------------------------------------------------->

        $msg =
            "GET /someurl.html HTTP/1.0\r\n" .
            "Host: localhost\r\n" .
            "X-My-Header: \x01\x02\x03 42\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: Invalid header syntax";
        $return[] = [$msg, $errCode, $errMsg];

        // 6 -------------------------------------------------------------------------------------->

        $msg =
            "GET  HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: invalid request line";
        $return[] = [$msg, $errCode, $errMsg];

        // 7 -------------------------------------------------------------------------------------->

        $msg =
            "GET http://localhost/ HTTP/1.1\r\n" .
            "Host: mis-matched.host\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: target host mis-matched to host header";
        $return[] = [$msg, $errCode, $errMsg];

        // 8 -------------------------------------------------------------------------------------->

        $msg =
            "CONNECT localhost/path HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: invalid connect target";
        $return[] = [$msg, $errCode, $errMsg];

        // 9 -------------------------------------------------------------------------------------->

        $msg =
            "GET localhost:1337 HTTP/1.1\r\n" .
            "Host: localhost:1337\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: authority-form only valid for CONNECT requests";
        $return[] = [$msg, $errCode, $errMsg];

        // 10 ------------------------------------------------------------------------------------->

        $msg =
            "GET / HTTP/1.1\r\n" .
            "Host: http://localhost:1337\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: invalid host header";
        $return[] = [$msg, $errCode, $errMsg];

        // x -------------------------------------------------------------------------------------->

        return $return;
    }

    /**
     * @dataProvider provideUpgradeBodySizeData
     */
    public function testUpgradeBodySizeContentLength(string $data, string $payload): void
    {
        $requestHandler = new ClosureRequestHandler(function (Request $req) use (&$request, &$body, $payload) {
            $request = $req;

            $body = $req->getBody();
            $body->increaseSizeLimit(\strlen($payload));

            $body = $request->getBody()->buffer();
        });

        $driver = new Http1Driver(
            $requestHandler,
            $this->createMock(ErrorHandler::class),
            new NullLogger,
            bodySizeLimit: 4,
        );

        async(fn () => $driver->handleClient(
            $this->createClientMock(),
            new ReadableBuffer($data),
            new WritableBuffer(),
        ));

        delay(0.1); // Allow parser generator to continue.

        self::assertInstanceOf(Request::class, $request);
        self::assertSame($payload, $body);
    }

    public function provideUpgradeBodySizeData()
    {
        $body = "abcdefghijklmnopqrstuvwxyz";

        $payload = $body;
        $data = "POST / HTTP/1.1\r\nHost:localhost\r\nConnection: keep-alive\r\nContent-Length: 26\r\n\r\n$payload";
        $return[] = [$data, $body];

        $payload = "2\r\nab\r\n3\r\ncde\r\n5\r\nfghij\r\n10\r\nklmnopqrstuvwxyz\r\n0\r\n\r\n";
        $data = "POST / HTTP/1.1\r\nHost:localhost\r\nConnection: keep-alive\r\nTransfer-Encoding: chunked\r\n\r\n$payload";
        $return[] = [$data, $body];

        return $return;
    }

    public function testClientDisconnectsDuringBufferingWithSizeLimit(): void
    {
        $requestHandler = new ClosureRequestHandler(function (Request $req) use (&$request, &$exception) {
            $request = $req;

            $body = $req->getBody();
            $body->increaseSizeLimit(3);

            try {
                $request->getBody()->buffer();
            } catch (ClientException $exception) {
            }
        });

        $driver = new Http1Driver(
            $requestHandler,
            $this->createMock(ErrorHandler::class),
            new NullLogger,
            bodySizeLimit: 1,
        );

        async(fn () => $driver->handleClient(
            $this->createClientMock(),
            new ReadableBuffer("POST / HTTP/1.1\r\nHost:localhost\r\nConnection: keep-alive\r\nContent-Length: 3\r\n\r\nab"),
            new WritableBuffer(),
        ));

        delay(0.1); // Allow parser generator to continue.

        self::assertInstanceOf(Request::class, $request);
        self::assertInstanceOf(ClientException::class, $exception);
    }

    public function testPipelinedRequests(): void
    {
        [$payloads, $results] = \array_map(null, ...$this->provideUpgradeBodySizeData());

        $responses = 0;

        $requestHandler = new ClosureRequestHandler(function (Request $req) use (&$request, &$responses) {
            $request = $req;
            $responses++;
        });

        $driver = new Http1Driver(
            $requestHandler,
            $this->createMock(ErrorHandler::class),
            new NullLogger,
        );

        async(fn () => $driver->handleClient(
            $this->createClientMock(),
            new ReadableBuffer($payloads[0] . $payloads[1] . $payloads[0]),
            new WritableBuffer(),
        ));

        delay(0.01); // Allow parser generator to continue.

        self::assertInstanceOf(Request::class, $request);

        self::assertSame($results[0], $request->getBody()->buffer());

        delay(0.01); // Allow parser generator to continue.

        self::assertInstanceOf(Request::class, $request);

        self::assertSame($results[1], $request->getBody()->buffer());

        $request = new Request($this->createClientMock(), "GET", Uri\Http::createFromString("/"));

        delay(0.01); // Allow parser generator to continue.

        self::assertInstanceOf(Request::class, $request);

        self::assertSame($results[0], $request->getBody()->buffer());

        $request = new Request($this->createClientMock(), "POST", Uri\Http::createFromString("/"));

        self::assertSame(3, $responses);
    }

    public function verifyWrite(string $input, int $status, array $headers, string $data): void
    {
        $actualBody = "";
        $parser = new Http1Parser(new ClientRequest("/"), static function ($chunk) use (&$actualBody) {
            $actualBody .= $chunk;
        }, $this->createCallback(0));

        $response = $parser->parse($input);
        while (!$parser->isComplete()) {
            $parser->parse($input);
        }

        $responseHeaders = $response->getHeaders();
        unset($responseHeaders['date']);

        self::assertEquals($status, $response->getStatus());
        self::assertEquals($headers, $responseHeaders);
        self::assertEquals($data, $actualBody);
    }

    public function testWrite(): void
    {
        $requestHandler = new ClosureRequestHandler(function (Request $req) use (&$request) {
            $request = $req;

            $queue = new Queue;

            $response = new Response(
                HttpStatus::OK,
                ["test" => ["successful"]],
                new ReadableIterableStream($queue->pipe())
            );
            $response->push("/foo");

            foreach (\str_split("foobar") as $c) {
                $queue->pushAsync($c)->ignore();
            }
            $queue->complete();

            return $response;
        });

        $driver = new Http1Driver(
            $requestHandler,
            $this->createMock(ErrorHandler::class),
            new NullLogger,
            connectionTimeout: 60,
        );

        $output = new WritableBuffer;

        async(fn () => $driver->handleClient(
            $this->createClientMock(),
            new ReadableBuffer("GET / HTTP/1.1\r\nHost: test.local\r\n\r\n"),
            $output,
        ));

        delay(0.1);

        self::assertTrue($output->isWritable());
        self::assertFalse($output->isClosed());

        $output->close();

        $this->verifyWrite($output->buffer(), 200, [
            "test" => ["successful"],
            "link" => ["</foo>; rel=preload"],
            "connection" => ["keep-alive"],
            "keep-alive" => ["timeout=60"],
            "transfer-encoding" => ["chunked"],
        ], "foobar");
    }

    /** @dataProvider provideWriteResponses */
    public function testResponseWrite(
        string $statusLine,
        Response $response,
        string $expectedRegexp,
        bool $expectedClosed
    ): void {
        $driver = new Http1Driver(
            new ClosureRequestHandler(function () use ($response) {
                return $response;
            }),
            $this->createMock(ErrorHandler::class),
            new NullLogger,
            connectionTimeout: 60,
        );

        $output = new WritableBuffer;

        async(fn () => $driver->handleClient(
            $this->createClientMock(),
            new ReadableBuffer("$statusLine\r\nHost: test.local\r\n\r\n"),
            $output,
        ));

        delay(0.1);

        self::assertSame($output->isClosed(), $expectedClosed);

        if (!$output->isClosed()) {
            $output->end();
        }

        self::assertMatchesRegularExpression('#' . $expectedRegexp . '#i', $output->buffer());
    }

    public function provideWriteResponses(): array
    {
        $data = [
            [
                "HEAD / HTTP/1.1",
                new Response(HttpStatus::OK, [], new ReadableBuffer),
                "HTTP/1.1 200 OK\r\nconnection: keep-alive\r\nkeep-alive: timeout=\d{2}\r\ndate: .* GMT\r\ntransfer-encoding: chunked\r\n\r\n",
                false,
            ],
            [
                "GET / HTTP/1.1",
                new Response(
                    HttpStatus::OK,
                    [],
                    new ReadableBuffer,
                    new Trailers(Future::complete(['test' => 'value']), ['test'])
                ),
                "HTTP/1.1 200 OK\r\nconnection: keep-alive\r\nkeep-alive: timeout=60\r\ndate: .* GMT\r\ntrailer: test\r\ntransfer-encoding: chunked\r\n\r\n0\r\ntest: value\r\n\r\n",
                false,
            ],
            [
                "GET / HTTP/1.1",
                new Response(HttpStatus::OK, ["content-length" => "0"], new ReadableBuffer),
                "HTTP/1.1 200 OK\r\ncontent-length: 0\r\nconnection: keep-alive\r\nkeep-alive: timeout=60\r\ndate: .* GMT\r\n\r\n",
                false,
            ],
            [
                "GET / HTTP/1.0",
                new Response(HttpStatus::OK, [], new ReadableBuffer),
                "HTTP/1.0 200 OK\r\nconnection: close\r\ndate: .* GMT\r\n\r\n",
                true,
            ],
        ];

        delay(0.1); // Tick event loop to complete the Trailers future.

        return $data;
    }

    public function testWriteAbortAfterHeaders(): void
    {
        $driver = new Http1Driver(
            new ClosureRequestHandler(fn () => new Response()),
            $this->createMock(ErrorHandler::class),
            new NullLogger,
        );

        $output = new WritableBuffer();

        async(fn () => $driver->handleClient(
            $this->createClientMock(),
            new ReadableBuffer("GET / HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\n\r\n"),
            $output,
        ));

        delay(0.1);

        self::assertFalse($output->isWritable());
        self::assertStringStartsWith("HTTP/1.0 200 OK\r\n", $output->buffer());
    }

    public function testHttp2Upgrade(): void
    {
        $settings = \strtr(\base64_encode(\pack("nN", 1, 1)), "+/", "-_");
        $payload = "GET /path HTTP/1.1\r\n" .
            "Host: foo.bar\r\n" .
            "Connection: upgrade\r\n" .
            "Upgrade: h2c\r\n" .
            "http2-settings: $settings\r\n" .
            "\r\n";

        $requestHandler = new ClosureRequestHandler(function (Request $req) use (&$request) {
            $request = $req;
            delay(0.1); // Add delay so response is not yet written to output buffer when checking for equality.
            return new Response;
        });

        $driver = new Http1Driver(
            $requestHandler,
            $this->createMock(ErrorHandler::class),
            new NullLogger,
            allowHttp2Upgrade: true,
        );

        $driver->handleClient(
            $this->createClientMock(),
            new ReadableBuffer($payload),
            $buffer = new WritableBuffer(),
        );

        $expected = [
            "HTTP/1.1 101 Switching Protocols",
            "connection: upgrade",
            "upgrade: h2c",
            "",
            Http2DriverTest::packFrame(\pack(
                "nNnNnNnN",
                Http2Parser::INITIAL_WINDOW_SIZE,
                HttpDriver::DEFAULT_BODY_SIZE_LIMIT,
                Http2Parser::MAX_CONCURRENT_STREAMS,
                Http2Driver::DEFAULT_CONCURRENT_STREAM_LIMIT,
                Http2Parser::MAX_HEADER_LIST_SIZE,
                HttpDriver::DEFAULT_HEADER_SIZE_LIMIT,
                Http2Parser::MAX_FRAME_SIZE,
                Http2Driver::DEFAULT_MAX_FRAME_SIZE
            ), Http2Parser::SETTINGS, Http2Parser::NO_FLAG)
            . Http2DriverTest::packFrame("", Http2Parser::SETTINGS, Http2Parser::ACK)
            . Http2DriverTest::packFrame(\pack("NN", 1, 0), Http2Parser::GOAWAY, Http2Parser::NO_FLAG)
        ];

        $expectedStr = \implode("\r\n", $expected);
        self::assertSame($expectedStr, $buffer->buffer());
        self::assertSame("foo.bar", $request->getUri()->getHost());
        self::assertSame("/path", $request->getUri()->getPath());
        self::assertSame("2", $request->getProtocolVersion());
    }

    public function testNativeHttp2(): void
    {
        $requestHandler = new ClosureRequestHandler(function (Request $req) use (&$request) {
            $request = $req;
        });

        $driver = new Http1Driver(
            $requestHandler,
            $this->createMock(ErrorHandler::class),
            new NullLogger,
            allowHttp2Upgrade: true,
        );

        $output = new WritableBuffer;

        $driver->handleClient(
            $this->createClientMock(),
            new ReadableBuffer("PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n"),
            $output,
        );

        $expected = Http2DriverTest::packFrame(\pack(
            "nNnNnNnN",
            Http2Parser::INITIAL_WINDOW_SIZE,
            HttpDriver::DEFAULT_BODY_SIZE_LIMIT,
            Http2Parser::MAX_CONCURRENT_STREAMS,
            Http2Driver::DEFAULT_CONCURRENT_STREAM_LIMIT,
            Http2Parser::MAX_HEADER_LIST_SIZE,
            HttpDriver::DEFAULT_HEADER_SIZE_LIMIT,
            Http2Parser::MAX_FRAME_SIZE,
            Http2Driver::DEFAULT_MAX_FRAME_SIZE
        ), Http2Parser::SETTINGS, Http2Parser::NO_FLAG)
        . Http2DriverTest::packFrame(\pack("NN", 0, 0), Http2Parser::GOAWAY, Http2Parser::NO_FLAG);

        $this->assertSame($expected, $output->buffer());
    }

    public function testExpect100Continue(): void
    {
        $requestHandler = new ClosureRequestHandler(function (Request $req) use (&$request) {
            $request = $req;

            return new Response();
        });

        $driver = new Http1Driver(
            $requestHandler,
            $this->createMock(ErrorHandler::class),
            new NullLogger,
        );

        $message = "POST / HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "Content-Length: 10\r\n" .
            "Expect: 100-continue\r\n" .
            "\r\n";

        $output = new WritableBuffer();

        async(fn () => $driver->handleClient(
            $this->createClientMock(),
            new ReadableBuffer($message),
            $output,
        ));

        delay(0.1);

        $output->close();

        /** @var Request $request */
        self::assertInstanceOf(Request::class, $request);
        self::assertSame("POST", $request->getMethod());
        self::assertSame("100-continue", $request->getHeader("expect"));

        self::assertStringStartsWith("HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\n", $output->buffer());
    }

    public function testTrailerHeaders(): void
    {
        $requestHandler = new ClosureRequestHandler(function (Request $req) use (&$request) {
            $request = $req;
        });

        $driver = new Http1Driver(
            $requestHandler,
            $this->createMock(ErrorHandler::class),
            new NullLogger,
        );

        $message =
            "GET /test HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "Transfer-Encoding: chunked\r\n" .
            "Trailer: My-Trailer\r\n" . // Note that Trailer is optional (RFC7230, section 4.4).
            "\r\n" .
            "c\r\n" .
            "Body Content\r\n" .
            "0\r\n" .
            "My-Trailer: 42\r\n" .
            "\r\n";

        async(fn () => $driver->handleClient(
            $this->createClientMock(),
            new ReadableBuffer($message),
            new WritableBuffer(),
        ));

        delay(0.1); // Allow parser generator to continue.

        self::assertInstanceOf(Request::class, $request);

        $body = $request->getBody()->buffer();

        self::assertSame("Body Content", $body);

        $trailers = $request->getTrailers()->await();

        self::assertInstanceOf(Message::class, $trailers);
        self::assertSame("42", $trailers->getHeader("My-Trailer"));
    }

    public function testSwitchingProtocolsUpgrade(): void
    {
        $requestHandler = new ClosureRequestHandler(function (Request $req) use (&$request): Response {
            $request = $req;
            $response = new Response();
            $response->upgrade($this->createCallback(1, expectArgs: [self::isInstanceOf(UpgradedSocket::class)]));
            return $response;
        });

        $driver = new Http1Driver(
            $requestHandler,
            $this->createMock(ErrorHandler::class),
            new NullLogger,
        );

        $message = "GET / HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "Connection: upgrade\r\n" .
            "Upgrade: test\r\n" .
            "\r\n";

        $output = new WritableBuffer();

        async(fn () => $driver->handleClient(
            $this->createClientMock(),
            new ReadableBuffer($message),
            $output,
        ));

        delay(0.1); // Allow parser generator to continue.

        $output->close();

        /** @var Request $request */
        self::assertInstanceOf(Request::class, $request);
        self::assertSame("GET", $request->getMethod());
        self::assertSame("upgrade", $request->getHeader("connection"));
        self::assertSame("test", $request->getHeader("upgrade"));

        self::assertStringStartsWith("HTTP/1.1 101 Switching Protocols\r\n", $output->buffer());
    }
}
