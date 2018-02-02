<?php

namespace Aerys\Test;

use Aerys\Client;
use Aerys\ClientException;
use Aerys\DefaultErrorHandler;
use Aerys\ErrorHandler;
use Aerys\Http1Driver;
use Aerys\Http2Driver;
use Aerys\Options;
use Aerys\Request;
use Aerys\Response;
use Aerys\Server;
use Aerys\TimeReference;
use Aerys\Trailers;
use Amp\Artax\Internal\Parser;
use Amp\ByteStream\InMemoryStream;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use Amp\Success;
use Amp\Uri\Uri;

class Http1DriverTest extends TestCase {
    /**
     * @dataProvider provideUnparsableRequests
     */
    public function testBadRequestBufferedParse(string $unparsable, int $errCode, string $errMsg, Options $options) {
        $writer = function (string $data) use (&$written): Promise {
            $written = $data;
            return new Success;
        };

        $driver = new Http1Driver(
            $options,
            $this->createMock(TimeReference::class),
            new DefaultErrorHandler // Using concrete instance to generate error response.
        );

        $client = $this->createMock(Client::class);
        $client->method('pendingResponseCount')
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
        $writer = function (string $data) use (&$written): Promise {
            $written = $data;
            return new Success;
        };

        $driver = new Http1Driver(
            $options,
            $this->createMock(TimeReference::class),
            new DefaultErrorHandler // Using concrete instance to generate error response.
        );

        $client = $this->createMock(Client::class);
        $client->method('pendingResponseCount')
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

        /** @var \Aerys\Request $request */
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

        for ($i = 0, $c = strlen($msg); $i < $c; $i++) {
            $promise = $parser->send($msg[$i]);
        }

        while ($promise instanceof Promise) {
            $promise = $parser->send("");
        }

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Aerys\Request $request */
        $body = Promise\wait($request->getBody()->buffer());

        $this->assertSame($expectations["protocol"], $request->getProtocolVersion(), "protocol mismatch");
        $this->assertSame($expectations["method"], $request->getMethod(), "method mismatch");
        $this->assertSame($expectations["uri"], $request->getUri()->getPath(), "uri mismatch");
        $this->assertSame($expectations["headers"], $request->getHeaders(), "headers mismatch");
        $this->assertSame($expectations["body"], $body, "body mismatch");
        $this->assertSame(80, $request->getUri()->getPort());
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

        /** @var \Aerys\Request $request */
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

        for ($i = 0, $c = strlen($msg); $i < $c; $i++) {
            $promise = $parser->send($msg[$i]);
        }

        while ($promise instanceof Promise) {
            $promise = $parser->send("");
        }

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Aerys\Request $request */
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

        // 4 --- chunked entity body -------------------------------------------------------------->

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

        // 5 --- chunked entity body with trailer headers ----------------------------------------->

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
        $opts = (new Options)->withMaxHeaderSize(128);
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 3 -------------------------------------------------------------------------------------->

        $msg =
            "GET /someurl.html HTTP/1.0\r\n" .
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
            "GET /someurl.html HTTP/1.0\r\n" .
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
        $errMsg = "Bad Request: authority-form does not allow a path component in the target";
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
            $body->increaseMaxSize(26);
            $request = $req;
        };

        $driver = new Http1Driver(
            (new Options)->withMaxBodySize(4),
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

        /** @var \Aerys\Request $request */
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

        $pendingResponses = 0;

        $resultEmitter = function (Request $req) use (&$request, &$pendingResponses) {
            $pendingResponses++;
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
            function ($data, $close) use (&$pendingResponses, &$parser) {
                $pendingResponses--;
                $parser->send(""); // Resume parser after waiting for response to be written.
                return new Success;
            }
        );

        $parser->send($payloads[0] . $payloads[1]);
        $parser->send(""); // Continue past yield for body emit.

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Aerys\Request $request */
        $body = Promise\wait($request->getBody()->buffer());

        $this->assertSame($results[0], $body);

        $writer = $driver->writer(new Response\EmptyResponse, $request);
        $request = null;
        $writer->send(null);

        $parser->send(""); // Continue past yield for body emit.

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Aerys\Request $request */
        $body = Promise\wait($request->getBody()->buffer());

        $this->assertSame($results[1], $body);

        $writer = $driver->writer(new Response\EmptyResponse);
        $request = null;
        $writer->send(null);

        $parser->send($payloads[0]);
        $parser->send(""); // Continue past yield for body emit.

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Aerys\Request $request */
        $body = Promise\wait($request->getBody()->buffer());

        $this->assertSame($results[0], $body);

        $writer = $driver->writer(new Response\EmptyResponse);
        $request = null;
        $writer->send(null);

        $this->assertSame(0, $pendingResponses);
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

    public function testWriter() {
        $headers = ["test" => ["successful"]];
        $status = 200;
        $data = "foobar";

        $server = $this->createMock(Server::class);
        $server->method('onTimeUpdate')
            ->willReturnCallback(function (callable $callback) {
                $callback(0, "date");
            });

        $driver = new Http1Driver(
            (new Options)->withConnectionTimeout(60),
            $this->createMock(TimeReference::class),
            $this->createMock(ErrorHandler::class)
        );

        $driver->setup(
            $this->createMock(Client::class),
            $this->createCallback(0),
            function (string $data, bool $close) use (&$buffer, &$fin) {
                $buffer = $data;
                $fin = $close;
                return new Success;
            }
        );

        $request = new Request($this->createMock(Client::class), "GET", new Uri("http://test.local"));

        $writer = $driver->writer($response = new Response(new InMemoryStream, $headers), $request);

        $response->push("/foo");

        foreach (str_split($data) as $c) {
            $writer->send($c);
        }
        $writer->send(null);

        $this->assertFalse($fin);
        $this->verifyWrite($buffer, $status, $headers + [
                "link" => ["</foo>; rel=preload"],
                "connection" => ["keep-alive"],
                "keep-alive" => ["timeout=60, max=1000"],
                "date" => [""], // Empty due to mock TimeReference
                "transfer-encoding" => ["chunked"],
            ], $data);
    }

    public function testWriterAbortAfterHeaders() {
        $driver = new Http1Driver(
            new Options,
            $this->createMock(TimeReference::class),
            $this->createMock(ErrorHandler::class)
        );

        $driver->setup(
            $this->createMock(Client::class),
            $this->createCallback(0),
            function (string $data, bool $close) use (&$invoked) {
                $this->assertTrue($close);
                $expected = "HTTP/1.0 200 OK";
                $this->assertEquals($expected, \substr($data, 0, \strlen($expected)));
                $invoked = true;
                return new Success;
            }
        );

        $writer = $driver->writer(new Response);

        $writer->send("foo");

        $this->assertNull($invoked);
        $writer->send(null);
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

        $options = new Options;

        $expected = [
            "HTTP/1.1 101 Switching Protocols",
            Http2DriverTest::packFrame(pack(
                "nNnNnN",
                Http2Driver::INITIAL_WINDOW_SIZE,
                $options->getMaxBodySize(),
                Http2Driver::MAX_CONCURRENT_STREAMS,
                $options->getMaxConcurrentStreams(),
                Http2Driver::MAX_HEADER_LIST_SIZE,
                $options->getMaxHeaderSize()
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
        $options = new Options;

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
                    "nNnNnN",
                    Http2Driver::INITIAL_WINDOW_SIZE,
                    $options->getMaxBodySize(),
                    Http2Driver::MAX_CONCURRENT_STREAMS,
                    $options->getMaxConcurrentStreams(),
                    Http2Driver::MAX_HEADER_LIST_SIZE,
                    $options->getMaxHeaderSize()
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

        /** @var \Aerys\Request $request */
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

        /** @var \Aerys\Request $request */
        $body = Promise\wait($request->getBody()->buffer());

        $this->assertSame("Body Content", $body);

        $trailers = Promise\wait($request->getBody()->getTrailers());

        $this->assertInstanceOf(Trailers::class, $trailers);
        $this->assertSame("42", $trailers->getHeader("My-Trailer"));
    }
}
