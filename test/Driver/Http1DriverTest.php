<?php

namespace Amp\Http\Server\Test\Driver;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\Future;
use Amp\Http\Client\Connection\Internal\Http1Parser;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Http2\Http2Parser;
use Amp\Http\Message;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\Http1Driver;
use Amp\Http\Server\Driver\Http2Driver;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Trailers;
use Amp\Http\Status;
use Amp\Pipeline\Queue;
use League\Uri;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;

class Http1DriverTest extends HttpDriverTest
{
    /**
     * @dataProvider provideUnparsableRequests
     */
    public function testBadRequestBufferedParse(string $unparsable, int $errCode, string $errMsg, Options $options): void
    {
        $written = "";
        $writer = function (string $data) use (&$written): Future {
            $written .= $data;
            return Future::complete();
        };

        $driver = new Http1Driver(
            $options,
            new DefaultErrorHandler, // Using concrete instance to generate error response.
            new NullLogger
        );

        $client = $this->createClientMock();
        $client->method('getPendingResponseCount')
            ->willReturn(1);

        $parser = $driver->setup($client, $this->createCallback(0), $writer);

        $parser->send($unparsable);

        delay(0.05); // Allow loop to tick a couple of times to complete request cycle.

        $expected = \sprintf("HTTP/1.0 %d %s", $errCode, $errMsg);
        $written = \substr($written, 0, \strlen($expected));

        self::assertSame($expected, $written);
    }

    /**
     * @dataProvider provideUnparsableRequests
     */
    public function testBadRequestIncrementalParse(string $unparsable, int $errCode, string $errMsg, Options $options): void
    {
        $written = "";
        $writer = function (string $data) use (&$written): Future {
            $written .= $data;
            return Future::complete();
        };

        $driver = new Http1Driver(
            $options,
            new DefaultErrorHandler, // Using concrete instance to generate error response.
            new NullLogger
        );

        $client = $this->createClientMock();
        $client->method('getPendingResponseCount')
            ->willReturn(1);

        $parser = $driver->setup($client, $this->createCallback(0), $writer);

        for ($i = 0, $c = \strlen($unparsable); $i < $c; $i++) {
            $parser->send($unparsable[$i]);
            if ($written) {
                break;
            }
        }

        delay(0.05); // Allow loop to tick a couple of times to complete request cycle.

        $expected = \sprintf("HTTP/1.0 %d %s", $errCode, $errMsg);
        $written = \substr($written, 0, \strlen($expected));

        self::assertSame($expected, $written);
    }

    /**
     * @dataProvider provideParsableRequests
     */
    public function testBufferedRequestParse(string $msg, array $expectations): void
    {
        $resultEmitter = function (Request $req) use (&$request): Future {
            $request = $req;
            return Future::complete();
        };

        $driver = new Http1Driver(
            new Options,
            $this->createMock(ErrorHandler::class),
            new NullLogger
        );

        $parser = $driver->setup(
            $this->createClientMock(),
            $resultEmitter,
            $this->createCallback(0)
        );

        $future = $parser->send($msg);
        while ($future instanceof Future) {
            $future = $parser->send(null);
        }

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
        $resultEmitter = function (Request $req) use (&$request): Future {
            $request = $req;
            return Future::complete();
        };

        $driver = new Http1Driver(
            new Options,
            $this->createMock(ErrorHandler::class),
            new NullLogger
        );

        $parser = $driver->setup(
            $this->createClientMock(),
            $resultEmitter,
            $this->createCallback(0)
        );

        for ($i = 0, $c = \strlen($msg); $i < $c; $i++) {
            $future = $parser->send($msg[$i]);
            while ($future instanceof Future) {
                $future = $parser->send(null);
            }
        }

        self::assertInstanceOf(Request::class, $request);

        /** @var Request $request */
        $body = $request->getBody()->buffer();

        $defaultPort = $request->getUri()->getScheme() === "https" ? 443 : 80;
        self::assertSame($expectations["protocol"], $request->getProtocolVersion(), "protocol mismatch");
        self::assertSame($expectations["method"], $request->getMethod(), "method mismatch");
        self::assertSame($expectations["uri"], $request->getUri()->getPath(), "uri mismatch");
        self::assertSame($expectations["headers"], $request->getHeaders(), "headers mismatch");
        self::assertSame($expectations["body"], $body, "body mismatch");
        self::assertSame(80, $request->getUri()->getPort() ?: $defaultPort);
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

        $resultEmitter = function (Request $req) use (&$request): Future {
            $request = $req;
            return Future::complete();
        };

        $driver = new Http1Driver(
            new Options,
            $this->createMock(ErrorHandler::class),
            new NullLogger
        );

        $parser = $driver->setup(
            $this->createClientMock(),
            $resultEmitter,
            $this->createCallback(0)
        );

        for ($i = 0, $c = \strlen($msg); $i < $c; $i++) {
            $future = $parser->send($msg[$i]);
        }

        while ($future instanceof Future) {
            $future = $parser->send(null);
        }

        self::assertInstanceOf(Request::class, $request);

        $body = $request->getBody()->buffer();

        self::assertSame($originalBody, $body);
    }

    /**
     * provide multiple chunk-sizes to test with.
     * @return \Generator
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

        $resultEmitter = function (Request $req) use (&$request): Future {
            $request = $req;
            return Future::complete();
        };

        $driver = new Http1Driver(
            new Options,
            $this->createMock(ErrorHandler::class),
            new NullLogger
        );

        $parser = $driver->setup(
            $this->createClientMock(),
            $resultEmitter,
            $this->createCallback(0)
        );

        foreach (\str_split($msg, $chunkSize) as $chunk) {
            $future = $parser->send($chunk);
            while ($future instanceof Future) {
                $future = $parser->send("");
            }
        }

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

        $msg = "OPTIONS * HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $trace = \substr($msg, 0, -2);

        $expectations = [
            "trace" => $trace,
            "protocol" => "1.1",
            "method" => "OPTIONS",
            "uri" => "",
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
            ["User-Agent", "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.11 (KHTML, like Gecko) Chrome/23.0.1271.97 Safari/537.11"],
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
        $opts = new Options;
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 1 -------------------------------------------------------------------------------------->

        $msg = "test   \r\n\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: invalid request line";
        $opts = new Options;
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 2 -------------------------------------------------------------------------------------->

        $msg =
            "GET /someurl.html HTTP/1.0\r\n" .
            "Host: localhost\r\n" .
            "X-My-Header: " . \str_repeat("x", 1024) . "r\n" .
            "\r\n";
        $errCode = 431;
        $errMsg = "Bad Request: header size violation";
        $opts = (new Options)->withHeaderSizeLimit(128);
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 3 -------------------------------------------------------------------------------------->

        $msg =
            "GET /someurl.html HTTP/1.1\r\n" .
            "Host: \r\n" .
            " localhost\r\n" .
            "X-My-Header: 42\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: Invalid header syntax: Obsolete line folding";
        $opts = new Options;
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 4 -------------------------------------------------------------------------------------->

        $msg =
            "GET /someurl.html HTTP/1.1\r\n" .
            "Host: \r\n\tlocalhost\r\n" .
            "X-My-Header: 42\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: Invalid header syntax: Obsolete line folding";
        $opts = new Options;
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 5 -------------------------------------------------------------------------------------->

        $msg =
            "GET /someurl.html HTTP/1.0\r\n" .
            "Host: localhost\r\n" .
            "X-My-Header: \x01\x02\x03 42\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: Invalid header syntax";
        $opts = new Options;
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 6 -------------------------------------------------------------------------------------->

        $msg =
            "GET  HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: invalid request line";
        $opts = new Options;
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 7 -------------------------------------------------------------------------------------->

        $msg =
            "GET http://localhost/ HTTP/1.1\r\n" .
            "Host: mis-matched.host\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: target host mis-matched to host header";
        $opts = new Options;
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 8 -------------------------------------------------------------------------------------->

        $msg =
            "CONNECT localhost/path HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: invalid connect target";
        $opts = new Options;
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 9 -------------------------------------------------------------------------------------->

        $msg =
            "GET localhost:1337 HTTP/1.1\r\n" .
            "Host: localhost:1337\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: authority-form only valid for CONNECT requests";
        $opts = new Options;
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 10 ------------------------------------------------------------------------------------->

        $msg =
            "GET / HTTP/1.1\r\n" .
            "Host: http://localhost:1337\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: invalid host header";
        $opts = new Options;
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // x -------------------------------------------------------------------------------------->

        return $return;
    }


    /**
     * @dataProvider provideUpgradeBodySizeData
     */
    public function testUpgradeBodySizeContentLength(string $data, string $payload): void
    {
        $onMessage = function (Request $req) use (&$request, $payload): Future {
            $body = $req->getBody();
            $body->increaseSizeLimit(\strlen($payload));
            $request = $req;
            return Future::complete();
        };

        $driver = new Http1Driver(
            (new Options)->withBodySizeLimit(4),
            $this->createMock(ErrorHandler::class),
            new NullLogger
        );

        $parser = $driver->setup(
            $this->createClientMock(),
            $onMessage,
            $this->createCallback(0)
        );

        $future = $parser->send($data);
        while ($future instanceof Future) {
            $future = $parser->send(null);
        }

        self::assertInstanceOf(Request::class, $request);

        /** @var $request */
        $body = $request->getBody()->buffer();

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

    public function testPipelinedRequests(): void
    {
        [$payloads, $results] = \array_map(null, ...$this->provideUpgradeBodySizeData());

        $responses = 0;

        $resultEmitter = function (Request $req) use (&$request, &$responses): Future {
            $responses++;
            $request = $req;
            return Future::complete();
        };

        $driver = new Http1Driver(
            new Options,
            $this->createMock(ErrorHandler::class),
            new NullLogger
        );

        $parser = $driver->setup(
            $this->createClientMock(),
            $resultEmitter,
            fn () => Future::complete(),
        );

        $parser->send($payloads[0] . $payloads[1]); // Send first two payloads simultaneously.

        delay(0.1);

        self::assertInstanceOf(Request::class, $request);

        EventLoop::queue(function () use (&$body, $request): void {
            $body = $request->getBody()->buffer();
        });

        while ($body === null) {
            $parser->send(null); // Continue past yields to body emits.
            delay(0.1);
        }

        self::assertSame($results[0], $body);

        EventLoop::queue(fn () => $driver->write($request, new Response));
        $request = null;
        $body = null;

        while ($request === null) {
            $parser->send(null); // Continue past yield to request emit.
            delay(0.1);
        }

        self::assertInstanceOf(Request::class, $request);

        EventLoop::queue(function () use (&$body, $request): void {
            $body = $request->getBody()->buffer();
        });

        while ($body === null) {
            $parser->send(null); // Continue past yields to body emits.
            delay(0.1);
        }

        self::assertSame($results[1], $body);

        $request = new Request($this->createClientMock(), "GET", Uri\Http::createFromString("/"));
        async(fn () => $driver->write($request, new Response));
        $request = null;
        $body = null;

        $parser->send(null); // Resume parser after last request.

        delay(0.1);

        $parser->send($payloads[0]); // Resume and send next body payload.

        while ($request === null) {
            $parser->send(null); // Continue past yield to request emit.
            delay(0.1);
        }

        self::assertInstanceOf(Request::class, $request);

        EventLoop::queue(function () use (&$body, $request): void {
            $body = $request->getBody()->buffer();
        });

        while ($body === null) {
            $parser->send(null); // Continue past yields to body emits.
            delay(0.1);
        }

        self::assertSame($results[0], $body);

        $request = new Request($this->createClientMock(), "POST", Uri\Http::createFromString("/"));
        async(fn () => $driver->write($request, new Response));
        $request = null;

        self::assertSame(3, $responses);

        delay(0.1);
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
        $headers = ["test" => ["successful"]];
        $status = 200;
        $data = "foobar";

        $driver = new Http1Driver(
            (new Options)->withHttp1Timeout(60),
            $this->createMock(ErrorHandler::class),
            new NullLogger
        );

        $buffer = "";

        $driver->setup(
            $this->createClientMock(),
            $this->createCallback(0),
            function (string $data, bool $close = false) use (&$buffer, &$fin) {
                $buffer .= $data;
                $fin = $close;
                return Future::complete();
            }
        );

        $queue = new Queue();

        $request = new Request($this->createClientMock(), "GET", Uri\Http::createFromString("http://test.local"));
        $response = new Response(Status::OK, $headers, new ReadableIterableStream($queue->pipe()));
        $response->push("/foo");

        async(fn () => $driver->write($request, $response));

        foreach (\str_split($data) as $c) {
            $queue->pushAsync($c)->ignore();
        }
        $queue->complete();

        delay(0.1);

        self::assertFalse($fin);
        $this->verifyWrite($buffer, $status, $headers + [
            "link" => ["</foo>; rel=preload"],
            "connection" => ["keep-alive"],
            "keep-alive" => ["timeout=60"],
            "transfer-encoding" => ["chunked"],
        ], $data);
    }

    /** @dataProvider provideWriteResponses */
    public function testResponseWrite(Request $request, Response $response, string $expectedRegexp, bool $expectedClosed): void
    {
        $driver = new Http1Driver(
            (new Options)->withHttp1Timeout(60),
            $this->createMock(ErrorHandler::class),
            new NullLogger
        );

        $buffer = "";
        $closed = false;

        $driver->setup(
            $this->createClientMock(),
            $this->createCallback(0),
            function (string $data, bool $close = false) use (&$buffer, &$closed): Future {
                $buffer .= $data;

                if ($close) {
                    $closed = true;
                }

                return Future::complete();
            }
        );

        $driver->write($request, $response);

        self::assertMatchesRegularExpression('#' . $expectedRegexp . '#i', $buffer);
        self::assertSame($closed, $expectedClosed);
    }

    public function provideWriteResponses(): array
    {
        $data = [
            [
                new Request($this->createClientMock(), "HEAD", Uri\Http::createFromString('/')),
                new Response(Status::OK, [], new ReadableBuffer),
                "HTTP/1.1 200 OK\r\nconnection: keep-alive\r\nkeep-alive: timeout=\d{2}\r\ndate: .* GMT\r\ntransfer-encoding: chunked\r\n\r\n",
                false,
            ],
            [
                new Request($this->createClientMock(), "GET", Uri\Http::createFromString('/')),
                new Response(Status::OK, [], new ReadableBuffer, new Trailers(Future::complete(['test' => 'value']), ['test'])),
                "HTTP/1.1 200 OK\r\nconnection: keep-alive\r\nkeep-alive: timeout=60\r\ndate: .* GMT\r\ntrailer: test\r\ntransfer-encoding: chunked\r\n\r\n0\r\ntest: value\r\n\r\n",
                false,
            ],
            [
                new Request($this->createClientMock(), "GET", Uri\Http::createFromString('/')),
                new Response(Status::OK, ["content-length" => 0], new ReadableBuffer),
                "HTTP/1.1 200 OK\r\ncontent-length: 0\r\nconnection: keep-alive\r\nkeep-alive: timeout=60\r\ndate: .* GMT\r\n\r\n",
                false,
            ],
            [
                new Request($this->createClientMock(), "GET", Uri\Http::createFromString('/'), [], '', '1.0'),
                new Response(Status::OK, [], new ReadableBuffer),
                "HTTP/1.0 200 OK\r\nconnection: close\r\ndate: .* GMT\r\n\r\n",
                true,
            ],
        ];

        delay(0.1); // Tick event loop to resolve the Trailers promise.

        return $data;
    }

    public function testWriteAbortAfterHeaders(): void
    {
        $driver = new Http1Driver(
            (new Options)->withStreamThreshold(1), // Set stream threshold to 1 to force immediate writes to client.
            $this->createMock(ErrorHandler::class),
            new NullLogger
        );

        $driver->setup(
            $this->createClientMock(),
            $this->createCallback(0),
            function (string $data, bool $close = false) use (&$invoked) {
                static $i = 0;

                // Headers are written with the first body chunk, then all remaining body chunks separately
                if (++$i === 1) {
                    $expected = "HTTP/1.0 200 OK";
                    $this->assertEquals($expected, \substr($data, 0, \strlen($expected)));
                    $this->assertFalse($close);
                } elseif ($i === 2) {
                    $this->assertTrue($close);
                    $invoked = true;
                }

                return Future::complete();
            }
        );

        $queue = new Queue();
        $request = new Request($this->createClientMock(), "GET", Uri\Http::createFromString('/'), [], '', '1.0');
        async(fn () => $driver->write($request, new Response(Status::OK, [], new ReadableIterableStream($queue->pipe()))));

        delay(0.1);

        $queue->pushAsync("foo")->ignore();
        self::assertNull($invoked);
        $queue->complete();

        delay(0.1);

        self::assertTrue($invoked);
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

        $options = (new Options)->withHttp2Upgrade();

        $expected = [
            "HTTP/1.1 101 Switching Protocols",
            Http2DriverTest::packFrame(\pack(
                "nNnNnNnN",
                Http2Parser::INITIAL_WINDOW_SIZE,
                $options->getBodySizeLimit(),
                Http2Parser::MAX_CONCURRENT_STREAMS,
                $options->getConcurrentStreamLimit(),
                Http2Parser::MAX_HEADER_LIST_SIZE,
                $options->getHeaderSizeLimit(),
                Http2Parser::MAX_FRAME_SIZE,
                Http2Driver::DEFAULT_MAX_FRAME_SIZE
            ), Http2Parser::SETTINGS, Http2Parser::NO_FLAG)
        ];

        $driver = new Http1Driver(
            $options,
            $this->createMock(ErrorHandler::class),
            new NullLogger
        );

        $parser = $driver->setup(
            $this->createClientMock(),
            function (Request $request) {
                $this->assertSame("foo.bar", $request->getUri()->getHost());
                $this->assertSame("/path", $request->getUri()->getPath());
                $this->assertSame("2.0", $request->getProtocolVersion());
            },
            function (string $data) use (&$expected): Future {
                $write = \array_shift($expected);
                $this->assertSame($write, \substr($data, 0, \strlen($write)));
                return Future::complete();
            }
        );

        $parser->send($payload);

        delay(0.1);
    }

    public function testNativeHttp2(): void
    {
        $options = (new Options)->withHttp2Upgrade();

        $driver = new Http1Driver(
            $options,
            $this->createMock(ErrorHandler::class),
            new NullLogger
        );

        $parser = $driver->setup(
            $this->createClientMock(),
            $this->createCallback(0),
            function (string $data) use ($options): Future {
                $expected = Http2DriverTest::packFrame(\pack(
                    "nNnNnNnN",
                    Http2Parser::INITIAL_WINDOW_SIZE,
                    $options->getBodySizeLimit(),
                    Http2Parser::MAX_CONCURRENT_STREAMS,
                    $options->getConcurrentStreamLimit(),
                    Http2Parser::MAX_HEADER_LIST_SIZE,
                    $options->getHeaderSizeLimit(),
                    Http2Parser::MAX_FRAME_SIZE,
                    Http2Driver::DEFAULT_MAX_FRAME_SIZE
                ), Http2Parser::SETTINGS, Http2Parser::NO_FLAG);

                $this->assertSame($expected, $data);

                return Future::complete();
            }
        );

        $parser->send("PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n");
    }

    public function testExpect100Continue(): void
    {
        $received = "";

        $driver = new Http1Driver(
            new Options,
            $this->createMock(ErrorHandler::class),
            new NullLogger
        );

        $parser = $driver->setup(
            $this->createClientMock(),
            function (Request $req) use (&$request): Future {
                $request = $req;
                return Future::complete();
            },
            function (string $data) use (&$received) {
                $received .= $data;
                return Future::complete();
            }
        );

        $message = "POST / HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "Content-Length: 10\r\n" .
            "Expect: 100-continue\r\n" .
            "\r\n";

        $parser->send($message);
        $parser->send(null); // Continue past yield sending 100 Continue response.

        delay(0.1);

        /** @var Request $request */
        self::assertInstanceOf(Request::class, $request);
        self::assertSame("POST", $request->getMethod());
        self::assertSame("100-continue", $request->getHeader("expect"));

        self::assertSame("HTTP/1.1 100 Continue\r\n\r\n", $received);
    }

    public function testTrailerHeaders(): void
    {
        $driver = new Http1Driver(
            new Options,
            $this->createMock(ErrorHandler::class),
            new NullLogger
        );

        $parser = $driver->setup(
            $this->createClientMock(),
            function (Request $req) use (&$request): Future {
                $request = $req;
                return Future::complete();
            },
            $this->createCallback(0)
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

        $future = $parser->send($message);
        while ($future instanceof Future) {
            $future = $parser->send(null);
        }

        self::assertInstanceOf(Request::class, $request);

        $body = $request->getBody()->buffer();

        self::assertSame("Body Content", $body);

        $trailers = $request->getTrailers()->await();

        self::assertInstanceOf(Message::class, $trailers);
        self::assertSame("42", $trailers->getHeader("My-Trailer"));
    }
}
