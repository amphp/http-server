<?php

namespace Aerys\Test;

use Aerys\Internal\Client;
use Aerys\Internal\Http1Driver;
use Aerys\Internal\HttpDriver;
use Aerys\Internal\Options;
use Aerys\Request;
use Aerys\Response;
use Aerys\Server;
use Amp\Artax\Internal\Parser;
use Amp\ByteStream\InMemoryStream;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use Amp\Uri\Uri;

class Http1DriverTest extends TestCase {
    /**
     * @dataProvider provideUnparsableRequests
     */
    public function testBadRequestBufferedParse($unparsable, $errCode, $errMsg, $opts) {
        $invoked = 0;
        $resultCode = null;
        $errorMsg = null;

        $emitCallback = function () use (&$invoked) {
            $invoked++;
        };

        $errorCallback = function (...$emitStruct) use (&$invoked, &$resultCode, &$errorMsg) {
            $invoked++;
            list(, $resultCode, $errorMsg) = $emitStruct;
        };

        $client = new Client;
        $client->options = new Options;
        foreach ($opts as $key => $val) {
            $client->options->$key = $val;
        }
        $driver = new Http1Driver;
        $driver->setup($this->createMock(Server::class), $emitCallback, $errorCallback, $this->createCallback(0));
        $parser = $driver->parser($client);
        $parser->send($unparsable);

        $this->assertTrue($invoked > 0);
        $this->assertSame($errCode, $resultCode);
        $this->assertSame($errMsg, $errorMsg);
    }

    /**
     * @dataProvider provideUnparsableRequests
     */
    public function testBadRequestIncrementalParse($unparsable, $errCode, $errMsg, $opts) {
        $invoked = 0;
        $resultCode = null;
        $errorMsg = null;

        $emitCallback = function () use (&$invoked) {
            $invoked++;
        };

        $errorCallback = function (...$emitStruct) use (&$invoked, &$resultCode, &$errorMsg) {
            $invoked++;
            list(, $resultCode, $errorMsg) = $emitStruct;
        };

        $client = new Client;
        $client->options = new Options;
        foreach ($opts as $key => $val) {
            $client->options->$key = $val;
        }
        $driver = new Http1Driver;
        $driver->setup($this->createMock(Server::class), $emitCallback, $errorCallback, $this->createCallback(0));
        $parser = $driver->parser($client);

        for ($i = 0, $c = strlen($unparsable); $i < $c; $i++) {
            $parser->send($unparsable[$i]);
            if ($errorMsg) {
                break;
            }
        }

        $this->assertTrue($invoked > 0);
        $this->assertSame($errCode, $resultCode);
        $this->assertSame($errMsg, $errorMsg);
    }

    /**
     * @dataProvider provideParsableRequests
     */
    public function testBufferedRequestParse(string $msg, array $expectations) {
        $resultEmitter = function ($client, Request $req) use (&$request) {
            $request = $req;
        };

        $client = new Client;
        $client->options = new Options;
        $driver = new Http1Driver;
        $driver->setup($this->createMock(Server::class), $resultEmitter, $this->createCallback(0), $this->createCallback(0));

        $parser = $driver->parser($client);
        $parser->send($msg);

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
        $resultEmitter = function ($client, Request $req) use (&$request) {
            $request = $req;
        };

        $client = new Client;
        $client->options = new Options;
        $client->serverPort = 80;
        $driver = new Http1Driver;
        $driver->setup($this->createMock(Server::class), $resultEmitter, $this->createCallback(0), $this->createCallback(0));

        $parser = $driver->parser($client);
        for ($i = 0, $c = strlen($msg); $i < $c; $i++) {
            $parser->send($msg[$i]);
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

        $resultEmitter = function ($client, Request $req) use (&$request) {
            $request = $req;
        };

        $client = new Client;
        $client->options = new Options;
        $driver = new Http1Driver;
        $driver->setup($this->createMock(Server::class), $resultEmitter, $this->createCallback(0), $this->createCallback(0));

        $parser = $driver->parser($client);
        for ($i = 0, $c = strlen($msg); $i < $c; $i++) {
            $parser->send($msg[$i]);
        }

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Aerys\Request $request */
        $body = Promise\wait($request->getBody()->buffer());

        $this->assertSame($originalBody, $body);
    }

    public function testChunkedBodyParseEmit() {
        $msg =
            "POST https://test.local:1337/post-endpoint HTTP/1.0\r\n" .
            "Host: localhost\r\n" .
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

        $resultEmitter = function ($client, Request $req) use (&$request) {
            $request = $req;
        };

        $client = new Client;
        $client->options = new Options;
        $client->options->ioGranularity = 1;
        $driver = new Http1Driver;
        $driver->setup($this->createMock(Server::class), $resultEmitter, $this->createCallback(0), $this->createCallback(0));

        $parser = $driver->parser($client);
        for ($i=0, $c=strlen($msg);$i<$c;$i++) {
            $parser->send($msg[$i]);
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
            "trace"       => $trace,
            "protocol"    => "1.1",
            "method"      => "GET",
            "uri"         => "/",
            "headers"     => ["host" => ["localhost"]],
            "body"        => "",
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
            "content-length" => ["3"]
        ];

        $expectations = [
            "trace"       => $trace,
            "protocol"    => "1.0",
            "method"      => "POST",
            "uri"         => "/post-endpoint",
            "headers"     => $headers,
            "body"        => "123",
        ];

        $return[] = [$msg, $expectations];

        // 2 --- OPTIONS request ------------------------------------------------------------------>

        $msg = "OPTIONS * HTTP/1.1\r\nHost: http://localhost\r\n\r\n";
        $trace = substr($msg, 0, -2);

        $expectations = [
            "trace"       => $trace,
            "protocol"    => "1.1",
            "method"      => "OPTIONS",
            "uri"         => "",
            "headers"     => ["host" => ["http://localhost"]],
            "body"        => "",
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
            "content-length" => ["5"]
        ];

        $expectations = [
            "trace"       => $trace,
            "protocol"    => "1.1",
            "method"      => "GET",
            "uri"         => "/test",
            "headers"     => $headers,
            "body"        => "12345",
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
            "trace"       => $trace,
            "protocol"    => "1.1",
            "method"      => "GET",
            "uri"         => "/test",
            "headers"     => $headers,
            "body"        => "woot!test",
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
            "trace"       => $trace,
            "protocol"    => "1.1",
            "method"      => "GET",
            "uri"         => "/test",
            "headers"     => $headers,
            "body"        => "woot!test",
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
        $opts = [];
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 1 -------------------------------------------------------------------------------------->

        $msg = "test   \r\n\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: invalid request line";
        $opts = [];
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 2 -------------------------------------------------------------------------------------->

        $msg =
            "GET /someurl.html HTTP/1.0\r\n" .
            "Host: localhost\r\n" .
            "X-My-Header: " . str_repeat("x", 1024) . "r\n" .
            "\r\n";
        $errCode = 431;
        $errMsg = "Bad Request: header size violation";
        $opts = ["maxHeaderSize" => 128];
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 3 -------------------------------------------------------------------------------------->

        $msg =
            "GET /someurl.html HTTP/1.0\r\n" .
            "Host: \r\n" .
            " localhost\r\n" .
            "X-My-Header: 42\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: multi-line headers deprecated by RFC 7230";
        $opts = [];
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 4 -------------------------------------------------------------------------------------->

        $msg =
            "GET /someurl.html HTTP/1.0\r\n" .
            "Host: \r\n\tlocalhost\r\n" .
            "X-My-Header: 42\r\n" .
            "\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: multi-line headers deprecated by RFC 7230";
        $opts = [];
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 5 -------------------------------------------------------------------------------------->

        /* //@TODO Messages with invalid CTL chars in their headers should fail
        $msg =
            "GET /someurl.html HTTP/1.0\r\n" .
            "Host: localhost\r\n" .
            "X-My-Header: \x01\x02\x03 42\r\n" .
            "\r\n"
        ;
        $errCode = 400;
        $errMsg = "Bad Request: header syntax violation";
        $opts = [];
        $return[] = [$msg, $errCode, $errMsg, $opts];
        */

        //

        // x -------------------------------------------------------------------------------------->

        return $return;
    }


    /**
     * @dataProvider provideUpgradeBodySizeData
     */
    public function testUpgradeBodySizeContentLength($data, $payload) {
        $client = new Client;

        $resultEmitter = function ($client, Request $req) use (&$request) {
            $body = $req->getBody();
            $body->increaseMaxSize(26);
            $request = $req;
        };

        $client->options = new Options;
        $client->options->maxBodySize = 4;
        $client->readWatcher = Loop::defer(function () {}); // dummy watcher

        $driver = new Http1Driver;
        $driver->setup($this->createMock(Server::class), $resultEmitter, $this->createCallback(0), $this->createCallback(0));
        $parser = $driver->parser($client);

        $client->requestParser = $parser;

        $parser->send($data);

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

        $client = new Client;

        $resultEmitter = function ($_, Request $req) use (&$request, $client) {
            $client->pendingResponses++;
            $request = $req;
        };

        $client->options = new Options;
        $client->remainingRequests = 3;
        $client->readWatcher = Loop::defer(function () {}); // dummy watcher
        $driver = new Http1Driver;
        $driver->setup(
            $this->createMock(Server::class),
            $resultEmitter,
            $this->createCallback(0),
            function ($client, $final = false) {
                $client->writeBuffer = "";
                if ($final) {
                    $client->pendingResponses--;
                }
            }
        );

        $parser = $driver->parser($client);
        $client->requestParser = $parser;

        $parser->send($payloads[0] . $payloads[1]);

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Aerys\Request $request */
        $body = Promise\wait($request->getBody()->buffer());

        $this->assertSame($results[0], $body);

        $writer = $driver->writer($client, new Response\EmptyResponse, $request);
        $request = null;
        $writer->send(null);

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Aerys\Request $request */
        $body = Promise\wait($request->getBody()->buffer());

        $this->assertSame($results[1], $body);

        $writer = $driver->writer($client, new Response\EmptyResponse);
        $request = null;
        $writer->send(null);

        $parser->send($payloads[0]);

        $this->assertInstanceOf(Request::class, $request);

        /** @var \Aerys\Request $request */
        $body = Promise\wait($request->getBody()->buffer());

        $this->assertSame($results[0], $body);

        $writer = $driver->writer($client, new Response\EmptyResponse);
        $request = null;
        $writer->send(null);

        $this->assertSame(0, $client->pendingResponses);
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
        $server->method('tick')
            ->willReturnCallback(function (callable $callback) {
                $callback(0, "date");
            });

        $driver = new Http1Driver;
        $driver->setup(
            $server,
            $this->createCallback(0),
            $this->createCallback(0),
            function (Client $client, string $data, bool $final = false) use (&$buffer, &$fin) {
                $buffer = $data;
                $fin = $final;
            }
        );
        $client = new Client;
        $client->options = new Options;
        $client->remainingRequests = PHP_INT_MAX;
        $client->options->connectionTimeout = 60;

        $request = new Request("GET", new Uri("http://test.local"));

        $writer = $driver->writer($client, $response = new Response(new InMemoryStream, $headers), $request);

        $response->push("/foo");

        foreach (str_split($data) as $c) {
            $writer->send($c);
        }
        $writer->send(null);

        $this->assertTrue($fin);
        $this->verifyWrite($buffer, $status, $headers + [
                "link" => ["</foo>; rel=preload"],
                "connection" => ["keep-alive"],
                "keep-alive" => ["timeout=60"],
                "date" => ["date"],
                "transfer-encoding" => ["chunked"],
            ], $data);
        $this->assertNotTrue($client->shouldClose);
    }

    public function testWriterAbortAfterHeaders() {
        $driver = new Http1Driver;
        $driver->setup(
            $this->createMock(Server::class),
            $this->createCallback(0),
            $this->createCallback(0),
            function (Client $client, string $data, bool $final) use (&$invoked) {
                $this->assertTrue($final);
                $this->assertTrue($client->shouldClose);
                $expected = "HTTP/1.0 200 OK";
                $this->assertEquals($expected, \substr($data, 0, \strlen($expected)));
                $invoked = true;
            }
        );

        $client = new Client;
        $client->options = new Options;
        $writer = $driver->writer($client, new Response);

        $writer->send("foo");

        $this->assertNull($invoked);
        $writer->send(null);
        $this->assertTrue($invoked);
    }

    public function testHttp2Upgrade() {
        $settings = \strtr(\base64_encode("somesettings"), "+/", "-_");
        $payload = "GET /path HTTP/1.1\r\n" .
            "Host: foo.bar\r\n" .
            "Connection: upgrade\r\n" .
            "Upgrade: h2c\r\n" .
            "http2-settings: $settings\r\n" .
            "\r\n";

        $client = new Client;
        $client->options = new Options;

        $driver = new Http1Driver;
        $driver->setup(
            $this->createMock(Server::class),
            $this->createCallback(1),
            $this->createCallback(0),
            $this->createCallback(1)
        );

        $http2 = new class implements HttpDriver {
            public $invoked = false;

            public function setup(Server $server, callable $onRequest, callable $onError, callable $responseWriter) {
            }
            public function writer(Client $client, Response $response, Request $request = null): \Generator {
                yield;
            }
            public function parser(Client $client): \Generator {
                $this->invoked = true;
                yield;
            }
        };

        $http2->setup(
            $this->createMock(Server::class),
            $this->createCallback(0),
            $this->createCallback(0),
            $this->createCallback(0)
        );

        // Set HTTP/2 driver with bound closure.
        (function () use ($http2) {
            $this->http2 = $http2;
        })->call($driver);

        $parser = $driver->parser($client);
        $parser->send($payload);

        $this->assertTrue($http2->invoked);
    }

    public function testNativeHttp2() {
        $driver = new Http1Driver;
        $http2 = new class implements HttpDriver {
            public function setup(Server $server, callable $onRequest, callable $onError, callable $responseWriter) {
            }
            public function writer(Client $client, Response $response, Request $request = null): \Generator {
                yield;
            }

            public $received;
            public function parser(Client $client): \Generator {
                while (1) {
                    $this->received .= yield;
                }
            }
        };

        (function () use ($http2) {
            $this->http2 = $http2;
        })->call($driver);

        $client = new Client;
        $client->options = new Options;
        $data = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\nbinary data";
        $driver->parser($client)->send($data);
        $this->assertEquals($data, $http2->received);
    }
}
