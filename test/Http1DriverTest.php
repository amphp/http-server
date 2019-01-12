<?php

namespace Amp\Http\Server\Test;

use Amp\Artax\Internal\Parser;
use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\IteratorStream;
use Amp\Emitter;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Driver\Http1Driver;
use Amp\Http\Server\Driver\Http2Driver;
use Amp\Http\Server\Driver\TimeReference;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Trailers;
use Amp\Http\Status;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use Amp\Success;
use League\Uri;

class Http1DriverTest extends TestCase {
    /**
     * @dataProvider provideUnparsableRequests
     */
    public function testBadRequestBufferedParse(string $unparsable, int $errCode, string $errMsg, Options $options) {
        $written = "";
        $writer = function (string $data) use (&$written): Promise {
            $written .= $data;
            return new Success;
        };

        $driver = new Http1Driver(
            $options,
            $this->createMock(TimeReference::class),
            new DefaultErrorHandler // Using concrete instance to generate error response.
        );

        $client = $this->createMock(Client::class);
        $client->method('getPendingResponseCount')
            ->willReturn(1);

        $parser = $driver->setup($client, $this->createCallback(0), $writer);

        $parser->send($unparsable);

        $expected = \sprintf("HTTP/1.0 %d %s", $errCode, $errMsg);
        $written = \substr($written, 0, \strlen($expected));

        $this->assertSame($expected, $written);
    }

    /**
     * @dataProvider provideUnparsableRequests
     */
    public function testBadRequestIncrementalParse(string $unparsable, int $errCode, string $errMsg, Options $options) {
        $written = "";
        $writer = function (string $data) use (&$written): Promise {
            $written .= $data;
            return new Success;
        };

        $driver = new Http1Driver(
            $options,
            $this->createMock(TimeReference::class),
            new DefaultErrorHandler // Using concrete instance to generate error response.
        );

        $client = $this->createMock(Client::class);
        $client->method('getPendingResponseCount')
            ->willReturn(1);

        $parser = $driver->setup($client, $this->createCallback(0), $writer);

        for ($i = 0, $c = \strlen($unparsable); $i < $c; $i++) {
            $parser->send($unparsable[$i]);
            if ($written) {
                break;
            }
        }

        $expected = \sprintf("HTTP/1.0 %d %s", $errCode, $errMsg);
        $written = \substr($written, 0, \strlen($expected));

        $this->assertSame($expected, $written);
    }

    /**
     * @dataProvider provideParsableRequests
     */
    public function testBufferedRequestParse(string $msg, array $expectations) {
        $resultEmitter = function (Request $req) use (&$request) {
            $request = $req;
            return new Success;
        };

        $driver = new Http1Driver(
            new Options,
            $this->createMock(TimeReference::class),
            $this->createMock(ErrorHandler::class)
        );

        $parser = $driver->setup(
            $this->createMock(Client::class),
            $resultEmitter,
            $this->createCallback(0)
        );

        $promise = $parser->send($msg);
        while ($promise instanceof Promise) {
            $promise = $parser->send("");
        }

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Amp\Http\Server\Request $request */
        $body = Promise\wait($request->getBody()->buffer());

        $this->assertSame($expectations["protocol"], $request->getProtocolVersion(), "protocol mismatch");
        $this->assertSame($expectations["method"], $request->getMethod(), "method mismatch");
        $this->assertSame($expectations["uri"], $request->getUri()->getPath(), "uri mismatch");
        $this->assertSame($expectations["headers"], $request->getHeaders(), "headers mismatch");
        $this->assertSame($expectations["body"], $body, "body mismatch");
    }

    /**
     * @dataProvider provideParsableRequests
     */
    public function testIncrementalRequestParse($msg, $expectations) {
        $resultEmitter = function (Request $req) use (&$request) {
            $request = $req;
        };

        $driver = new Http1Driver(
            new Options,
            $this->createMock(TimeReference::class),
            $this->createMock(ErrorHandler::class)
        );

        $parser = $driver->setup(
            $this->createMock(Client::class),
            $resultEmitter,
            $this->createCallback(0)
        );

        for ($i = 0, $c = \strlen($msg); $i < $c; $i++) {
            $promise = $parser->send($msg[$i]);
        }

        while ($promise instanceof Promise) {
            $promise = $parser->send("");
        }

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Amp\Http\Server\Request $request */
        $body = Promise\wait($request->getBody()->buffer());

        $defaultPort = $request->getUri()->getScheme() === "https" ? 443 : 80;
        $this->assertSame($expectations["protocol"], $request->getProtocolVersion(), "protocol mismatch");
        $this->assertSame($expectations["method"], $request->getMethod(), "method mismatch");
        $this->assertSame($expectations["uri"], $request->getUri()->getPath(), "uri mismatch");
        $this->assertSame($expectations["headers"], $request->getHeaders(), "headers mismatch");
        $this->assertSame($expectations["body"], $body, "body mismatch");
        $this->assertSame(80, $request->getUri()->getPort() ?: $defaultPort);
    }

    public function testIdentityBodyParseEmit() {
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

        $resultEmitter = function (Request $req) use (&$request) {
            $request = $req;
        };

        $driver = new Http1Driver(
            new Options,
            $this->createMock(TimeReference::class),
            $this->createMock(ErrorHandler::class)
        );

        $parser = $driver->setup(
            $this->createMock(Client::class),
            $resultEmitter,
            $this->createCallback(0)
        );

        for ($i = 0, $c = strlen($msg); $i < $c; $i++) {
            $promise = $parser->send($msg[$i]);
        }

        while ($promise instanceof Promise) {
            $promise = $parser->send("");
        }

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Amp\Http\Server\Request $request */
        $body = Promise\wait($request->getBody()->buffer());

        $this->assertSame($originalBody, $body);
    }

    public function testChunkedBodyParseEmit() {
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

        $resultEmitter = function (Request $req) use (&$request) {
            $request = $req;
        };

        $driver = new Http1Driver(
            new Options,
            $this->createMock(TimeReference::class),
            $this->createMock(ErrorHandler::class)
        );

        $parser = $driver->setup(
            $this->createMock(Client::class),
            $resultEmitter,
            $this->createCallback(0)
        );

        for ($i = 0, $c = \strlen($msg); $i < $c; $i++) {
            $promise = $parser->send($msg[$i]);
        }

        while ($promise instanceof Promise) {
            $promise = $parser->send("");
        }

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Amp\Http\Server\Request $request */
        $body = Promise\wait($request->getBody()->buffer());

        $this->assertSame($expectedBody, $body);
    }

    public function provideParsableRequests() {
        $return = [];

        // 0 --- basic request -------------------------------------------------------------------->

        $msg =
            "GET / HTTP/1.1" . "\r\n" .
            "Host: localhost" . "\r\n" .
            "\r\n";
        $trace = substr($msg, 0, -2);
        $expectations = [
            "trace" => $trace,
            "protocol" => "1.1",
            "method" => "GET",
            "uri" => "/",
            "headers" => ["host" => ["localhost"]],
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
        $trace = explode("\r\n", $msg);
        array_pop($trace);
        $trace = implode("\r\n", $trace);

        $headers = [
            "host" => ["localhost:80"],
            "cookie" => ["cookie1", "cookie2"],
            "content-length" => ["3"],
        ];

        $expectations = [
            "trace" => $trace,
            "protocol" => "1.0",
            "method" => "POST",
            "uri" => "/post-endpoint",
            "headers" => $headers,
            "body" => "123",
        ];

        $return[] = [$msg, $expectations];

        // 2 --- OPTIONS request ------------------------------------------------------------------>

        $msg = "OPTIONS * HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $trace = substr($msg, 0, -2);

        $expectations = [
            "trace" => $trace,
            "protocol" => "1.1",
            "method" => "OPTIONS",
            "uri" => "",
            "headers" => ["host" => ["localhost"]],
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

        $expectations = [
            "trace" => $trace,
            "protocol" => "1.1",
            "method" => "GET",
            "uri" => "/test",
            "headers" => $headers,
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

        $expectations = [
            "trace" => $trace,
            "protocol" => "1.0",
            "method" => "GET",
            "uri" => "/someurl.html",
            "headers" => $headers,
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

        $expectations = [
            "trace" => $trace,
            "protocol" => "1.1",
            "method" => "GET",
            "uri" => "/test",
            "headers" => $headers,
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
            //"my-trailer" => ["42"],
        ];

        $expectations = [
            "trace" => $trace,
            "protocol" => "1.1",
            "method" => "GET",
            "uri" => "/test",
            "headers" => $headers,
            "body" => "woot!test",
        ];

        $return[] = [$msg, $expectations];

        // x -------------------------------------------------------------------------------------->

        return $return;
    }

    public function provideUnparsableRequests() {
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
            "X-My-Header: " . str_repeat("x", 1024) . "r\n" .
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
    public function testUpgradeBodySizeContentLength($data, $payload) {
        $resultEmitter = function (Request $req) use (&$request) {
            $body = $req->getBody();
            $body->increaseSizeLimit(26);
            $request = $req;
        };

        $driver = new Http1Driver(
            (new Options)->withBodySizeLimit(4),
            $this->createMock(TimeReference::class),
            $this->createMock(ErrorHandler::class)
        );

        $parser = $driver->setup(
            $this->createMock(Client::class),
            $resultEmitter,
            $this->createCallback(0)
        );

        $promise = $parser->send($data);
        while ($promise instanceof Promise) {
            $promise = $parser->send("");
        }

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Amp\Http\Server\Request $request */
        $body = Promise\wait($request->getBody()->buffer());

        $this->assertSame($payload, $body);
    }

    public function provideUpgradeBodySizeData() {
        $body = "abcdefghijklmnopqrstuvwxyz";

        $payload = $body;
        $data = "POST / HTTP/1.1\r\nHost:localhost\r\nConnection: keep-alive\r\nContent-Length: 26\r\n\r\n$payload";
        $return[] = [$data, $body];

        $payload = "2\r\nab\r\n3\r\ncde\r\n5\r\nfghij\r\n10\r\nklmnopqrstuvwxyz\r\n0\r\n\r\n";
        $data = "POST / HTTP/1.1\r\nHost:localhost\r\nConnection: keep-alive\r\nTransfer-Encoding: chunked\r\n\r\n$payload";
        $return[] = [$data, $body];

        return $return;
    }

    public function testPipelinedRequests() {
        list($payloads, $results) = array_map(null, ...$this->provideUpgradeBodySizeData());

        $responses = 0;

        $resultEmitter = function (Request $req) use (&$request, &$responses) {
            $responses++;
            $request = $req;
            return new Success;
        };

        $driver = new Http1Driver(
            new Options,
            $this->createMock(TimeReference::class),
            $this->createMock(ErrorHandler::class)
        );

        $parser = $driver->setup(
            $this->createMock(Client::class),
            $resultEmitter,
            function () {
                return new Success;
            }
        );

        $parser->send($payloads[0] . $payloads[1]); // Send first two payloads simultaneously.

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Amp\Http\Server\Request $request */
        $request->getBody()->buffer()->onResolve(function ($exception, $data) use (&$body) {
            $body = $data;
        });

        while ($body === null) {
            $parser->send(""); // Continue past yields to body emits.
        }

        $this->assertSame($results[0], $body);

        $driver->write($request, new Response);
        $request = null;
        $body = null;

        while ($request === null) {
            $parser->send(""); // Continue past yield to request emit.
        }

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Amp\Http\Server\Request $request */
        $request->getBody()->buffer()->onResolve(function ($exception, $data) use (&$body) {
            $body = $data;
        });

        while ($body === null) {
            $parser->send(""); // Continue past yields to body emits.
        }

        $this->assertSame($results[1], $body);

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/"));
        $driver->write($request, new Response);
        $request = null;
        $body = null;

        $parser->send($payloads[0]); // Resume and send next body payload.

        while ($request === null) {
            $parser->send(""); // Continue past yield to request emit.
        }

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Amp\Http\Server\Request $request */
        $request->getBody()->buffer()->onResolve(function ($exception, $data) use (&$body) {
            $body = $data;
        });

        while ($body === null) {
            $parser->send(""); // Continue past yields to body emits.
        }

        $this->assertSame($results[0], $body);

        $request = new Request($this->createMock(Client::class), "POST", Uri\Http::createFromString("/"));
        $driver->write($request, new Response);
        $request = null;

        $this->assertSame(3, $responses);
    }

    public function verifyWrite($input, $status, $headers, $data) {
        $actualBody = "";
        $parser = new Parser(static function ($chunk) use (&$actualBody) {
            $actualBody .= $chunk;
        }, Parser::MODE_RESPONSE);
        $parsed = $parser->parse($input);
        if ($parsed["headersOnly"]) {
            $parser->parse();
        }
        $this->assertEquals($status, $parsed["status"]);
        $this->assertEquals($headers, $parsed["headers"]);
        $this->assertEquals($data, $actualBody);
    }

    public function testWrite() {
        $headers = ["test" => ["successful"]];
        $status = 200;
        $data = "foobar";

        $driver = new Http1Driver(
            (new Options)->withConnectionTimeout(60),
            $this->createMock(TimeReference::class),
            $this->createMock(ErrorHandler::class)
        );

        $buffer = "";

        $driver->setup(
            $this->createMock(Client::class),
            $this->createCallback(0),
            function (string $data, bool $close = false) use (&$buffer, &$fin) {
                $buffer .= $data;
                $fin = $close;
                return new Success;
            }
        );

        $emitter = new Emitter;

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("http://test.local"));
        $response = new Response(Status::OK, $headers, new IteratorStream($emitter->iterate()));
        $response->push("/foo");

        $driver->write($request, $response);

        foreach (str_split($data) as $c) {
            $emitter->emit($c);
        }
        $emitter->complete();

        $this->assertFalse($fin);
        $this->verifyWrite($buffer, $status, $headers + [
            "link" => ["</foo>; rel=preload"],
            "connection" => ["keep-alive"],
            "keep-alive" => ["timeout=60"],
            "date" => [""], // Empty due to mock TimeReference
            "transfer-encoding" => ["chunked"],
        ], $data);
    }

    /** @dataProvider provideWriteResponses */
    public function testResponseWrite(Request $request, Response $response, string $expectedBuffer, bool $expectedClosed) {
        $driver = new Http1Driver(
            (new Options)->withConnectionTimeout(60),
            $this->createMock(TimeReference::class),
            $this->createMock(ErrorHandler::class)
        );

        $buffer = "";
        $closed = false;

        $driver->setup(
            $this->createMock(Client::class),
            $this->createCallback(0),
            function (string $data, bool $close = false) use (&$buffer, &$closed) {
                $buffer .= $data;

                if ($close) {
                    $closed = true;
                }

                return new Success;
            }
        );

        Promise\wait($driver->write($request, $response));

        $this->assertSame($buffer, $expectedBuffer);
        $this->assertSame($closed, $expectedClosed);
    }

    public function provideWriteResponses() {
        return [
            [
                new Request($this->createMock(Client::class), "HEAD", Uri\Http::createFromString("/")),
                new Response(Status::OK, [], new InMemoryStream),
                "HTTP/1.1 200 OK\r\nconnection: keep-alive\r\nkeep-alive: timeout=60\r\ndate: \r\ntransfer-encoding: chunked\r\n\r\n",
                false,
            ],
            [
                new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/")),
                new Response(Status::OK, [], new InMemoryStream),
                "HTTP/1.1 200 OK\r\nconnection: keep-alive\r\nkeep-alive: timeout=60\r\ndate: \r\ntransfer-encoding: chunked\r\n\r\n0\r\n\r\n",
                false,
            ],
            [
                new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/")),
                new Response(Status::OK, ["content-length" => 0], new InMemoryStream),
                "HTTP/1.1 200 OK\r\ncontent-length: 0\r\nconnection: keep-alive\r\nkeep-alive: timeout=60\r\ndate: \r\n\r\n",
                false,
            ],
            [
                new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/"), [], null, "1.0"),
                new Response(Status::OK, [], new InMemoryStream),
                "HTTP/1.0 200 OK\r\nconnection: close\r\ndate: \r\n\r\n",
                true,
            ],
        ];
    }

    public function testWriteAbortAfterHeaders() {
        $driver = new Http1Driver(
            new Options,
            $this->createMock(TimeReference::class),
            $this->createMock(ErrorHandler::class)
        );

        $driver->setup(
            $this->createMock(Client::class),
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

                return new Success;
            }
        );

        $emitter = new Emitter;
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/"), [], null, "1.0");
        $driver->write($request, new Response(Status::OK, [], new IteratorStream($emitter->iterate())));

        $emitter->emit("foo");
        $this->assertNull($invoked);
        $emitter->complete();
        $this->assertTrue($invoked);
    }

    public function testHttp2Upgrade() {
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
            Http2DriverTest::packFrame(pack(
                "nNnNnNnN",
                Http2Driver::INITIAL_WINDOW_SIZE,
                $options->getBodySizeLimit(),
                Http2Driver::MAX_CONCURRENT_STREAMS,
                $options->getConcurrentStreamLimit(),
                Http2Driver::MAX_HEADER_LIST_SIZE,
                $options->getHeaderSizeLimit(),
                Http2Driver::MAX_FRAME_SIZE,
                Http2Driver::DEFAULT_MAX_FRAME_SIZE
            ), Http2Driver::SETTINGS, Http2Driver::NOFLAG, 0)
        ];

        $driver = new Http1Driver(
            $options,
            $this->createMock(TimeReference::class),
            $this->createMock(ErrorHandler::class)
        );

        $parser = $driver->setup(
            $this->createMock(Client::class),
            function (Request $request) {
                $this->assertSame("foo.bar", $request->getUri()->getHost());
                $this->assertSame("/path", $request->getUri()->getPath());
                $this->assertSame("2.0", $request->getProtocolVersion());
            },
            function (string $data) use (&$expected) {
                $write = \array_shift($expected);
                $this->assertSame($write, \substr($data, 0, \strlen($write)));
                return new Success;
            }
        );

        $parser->send($payload);
    }

    public function testNativeHttp2() {
        $options = (new Options)->withHttp2Upgrade();

        $driver = new Http1Driver(
            $options,
            $this->createMock(TimeReference::class),
            $this->createMock(ErrorHandler::class)
        );

        $parser = $driver->setup(
            $this->createMock(Client::class),
            $this->createCallback(0),
            function (string $data) use ($options) {
                $expected = Http2DriverTest::packFrame(pack(
                    "nNnNnNnN",
                    Http2Driver::INITIAL_WINDOW_SIZE,
                    $options->getBodySizeLimit(),
                    Http2Driver::MAX_CONCURRENT_STREAMS,
                    $options->getConcurrentStreamLimit(),
                    Http2Driver::MAX_HEADER_LIST_SIZE,
                    $options->getHeaderSizeLimit(),
                    Http2Driver::MAX_FRAME_SIZE,
                    Http2Driver::DEFAULT_MAX_FRAME_SIZE
                ), Http2Driver::SETTINGS, Http2Driver::NOFLAG, 0);

                $this->assertSame($expected, $data);

                return new Success;
            }
        );

        $parser->send("PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n");
    }

    public function testExpect100Continue() {
        $received = "";

        $driver = new Http1Driver(
            new Options,
            $this->createMock(TimeReference::class),
            $this->createMock(ErrorHandler::class)
        );

        $parser = $driver->setup(
            $this->createMock(Client::class),
            function (Request $req) use (&$request) {
                $request = $req;
            },
            function (string $data) use (&$received) {
                $received .= $data;
                return new Success;
            }
        );

        $message = "POST / HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "Content-Length: 10\r\n" .
            "Expect: 100-continue\r\n" .
            "\r\n";

        $parser->send($message);
        $parser->send(""); // Continue past yield sending 100 Continue response.

        /** @var \Amp\Http\Server\Request $request */
        $this->assertInstanceOf(Request::class, $request);
        $this->assertSame("POST", $request->getMethod());
        $this->assertSame("100-continue", $request->getHeader("expect"));

        $this->assertSame("HTTP/1.1 100 Continue\r\n\r\n", $received);
    }

    public function testTrailerHeaders() {
        $driver = new Http1Driver(
            new Options,
            $this->createMock(TimeReference::class),
            $this->createMock(ErrorHandler::class)
        );

        $parser = $driver->setup(
            $this->createMock(Client::class),
            function (Request $req) use (&$request) {
                $request = $req;
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

        $promise = $parser->send($message);
        while ($promise instanceof Promise) {
            $promise = $parser->send("");
        }

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Amp\Http\Server\Request $request */
        $body = Promise\wait($request->getBody()->buffer());

        $this->assertSame("Body Content", $body);

        $trailers = Promise\wait($request->getBody()->getTrailers());

        $this->assertInstanceOf(Trailers::class, $trailers);
        $this->assertSame("42", $trailers->getHeader("My-Trailer"));
    }
}
