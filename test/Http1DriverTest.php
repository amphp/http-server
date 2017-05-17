<?php

namespace Aerys\Test;

use Aerys\Client;
use Aerys\HttpDriver;
use Aerys\Http1Driver;
use Aerys\Http2Driver;
use Aerys\InternalRequest;
use Aerys\Options;
use Amp\Artax\Parser;
use Amp\Loop;
use PHPUnit\Framework\TestCase;

class Http1DriverTest extends TestCase {

    /**
     * @dataProvider provideUnparsableRequests
     */
    function testBadRequestBufferedParse($unparsable, $errCode, $errMsg, $opts) {
        $invoked = 0;
        $resultCode = null;
        $parseResult = null;
        $errorMsg = null;

        $emitCallback = function(...$emitStruct) use (&$invoked, &$resultCode, &$parseResult, &$errorMsg) {
            $invoked++;
            list(, $resultCode, $parseResult, $errorMsg) = $emitStruct;
        };

        $client = new Client;
        $client->options = new Options;
        foreach ($opts as $key => $val) {
            $client->options->$key = $val;
        }
        $driver = new Http1Driver;
        $driver->setup($emitCallback, function(){});
        $parser = $driver->parser($client);
        $parser->send($unparsable);

        $this->assertTrue($invoked > 0);
        $this->assertSame(HttpDriver::ERROR, $resultCode);
        $this->assertSame($errMsg, $errorMsg);
    }

    /**
     * @dataProvider provideUnparsableRequests
     */
    function testBadRequestIncrementalParse($unparsable, $errCode, $errMsg, $opts) {
        $invoked = 0;
        $resultCode = null;
        $parseResult = null;
        $errorMsg = null;

        $emitCallback = function(...$emitStruct) use (&$invoked, &$resultCode, &$parseResult, &$errorMsg) {
            $invoked++;
            list(, $resultCode, $parseResult, $errorMsg) = $emitStruct;
        };

        $client = new Client;
        $client->options = new Options;
        foreach ($opts as $key => $val) {
            $client->options->$key = $val;
        }
        $driver = new Http1Driver;
        $driver->setup($emitCallback, function(){});
        $parser = $driver->parser($client);

        for ($i = 0, $c = strlen($unparsable); $i < $c; $i++) {
            $parser->send($unparsable[$i]);
            if ($errorMsg) {
                break;
            }
        }

        $this->assertTrue($invoked > 0);
        $this->assertSame(HttpDriver::ERROR, $resultCode);
        $this->assertSame($errMsg, $errorMsg);
    }

    /**
     * @dataProvider provideParsableRequests
     */
    function testBufferedRequestParse($msg, $expectations) {
        $invoked = 0;
        $parseResult = null;
        $body = "";

        $emitCallback = function(...$emitStruct) use (&$invoked, &$parseResult, &$body) {
            $invoked++;
            list(, $resultCode, $parseResult, $errorStruct) = $emitStruct;
            $this->assertNull($errorStruct);
            $body .= $parseResult["body"];
        };

        $client = new Client;
        $client->options = new Options;
        $driver = new Http1Driver;
        $driver->setup($emitCallback, function(){});
        $parser = $driver->parser($client);
        $parser->send($msg);

        $this->assertSame($expectations["invocations"], $invoked, "invocations mismatch");
        $this->assertSame($expectations["trace"], $parseResult["trace"], "trace mismatch");
        $this->assertSame($expectations["protocol"], $parseResult["protocol"], "protocol mismatch");
        $this->assertSame($expectations["method"], $parseResult["method"], "method mismatch");
        $this->assertSame($expectations["uri"], $parseResult["uri"], "uri mismatch");
        $this->assertSame($expectations["headers"], $parseResult["headers"], "headers mismatch");
        $this->assertSame($expectations["body"], $body, "body mismatch");
    }

    /**
     * @dataProvider provideParsableRequests
     */
    function testIncrementalRequestParse($msg, $expectations) {
        $invoked = 0;
        $parseResult = null;
        $body = "";

        $emitCallback = function(...$emitStruct) use (&$invoked, &$parseResult, &$body) {
            $invoked++;
            list(, $resultCode, $parseResult, $errorStruct) = $emitStruct;
            $this->assertNull($errorStruct);
            $body .= $parseResult["body"];
        };

        $client = new Client;
        $client->options = new Options;
        $driver = new Http1Driver;
        $driver->setup($emitCallback, function(){});
        $parser = $driver->parser($client);
        for($i = 0, $c = strlen($msg); $i < $c; $i++) {
            $parser->send($msg[$i]);
        }

        $this->assertSame($expectations["invocations"], $invoked, "invocations mismatch");
        $this->assertSame($expectations["trace"], $parseResult["trace"], "trace mismatch");
        $this->assertSame($expectations["protocol"], $parseResult["protocol"], "protocol mismatch");
        $this->assertSame($expectations["method"], $parseResult["method"], "method mismatch");
        $this->assertSame($expectations["uri"], $parseResult["uri"], "uri mismatch");
        $this->assertSame($expectations["headers"], $parseResult["headers"], "headers mismatch");
        $this->assertSame($expectations["body"], $body, "body mismatch");
    }

    function testIdentityBodyParseEmit() {
        $originalBody = "12345";
        $msg =
            "POST /post-endpoint HTTP/1.0\r\n" .
            "Host: localhost\r\n" .
            "Cookie: cookie1\r\n" .
            "Cookie: cookie2\r\n" .
            "Content-Length: 10\r\n" .
            "\r\n" .
            $originalBody
        ;

        $invoked = 0;
        $body = "";

        $emitCallback = function(...$emitStruct) use (&$invoked, &$body) {
            $invoked++;
            $body .= $emitStruct[2]["body"];
        };

        $client = new Client;
        $client->options = new Options;
        $client->options->ioGranularity = 1;
        $driver = new Http1Driver;
        $driver->setup($emitCallback, function(){});
        $parser = $driver->parser($client);
        for($i = 0, $c = strlen($msg); $i < $c; $i++) {
            $parser->send($msg[$i]);
        }

        // once for headers and once for each body byte
        $this->assertSame(strlen($originalBody) + 1, $invoked);
        $this->assertSame($originalBody, $body);
    }

    function testStreamingBodyParseEmit() {
        $body = "";
        $invoked = 0;
        $emitCallback = function(...$emitStruct) use (&$invoked, &$body) {
            $invoked++;
            list(, $parseCode, $parseResultArr) = $emitStruct;
            switch($invoked) {
                case 1:
                    $this->assertSame(HttpDriver::ENTITY_HEADERS, $parseCode);
                    $this->assertSame("", $parseResultArr["body"]);
                    break;
                case 2:
                    $this->assertSame(HttpDriver::ENTITY_PART, $parseCode);
                    $this->assertSame("1\r\n", $parseResultArr["body"]);
                    break;
                case 3:
                    $this->assertSame(HttpDriver::ENTITY_PART, $parseCode);
                    $this->assertSame("2", $parseResultArr["body"]);
                    break;
                case 4:
                    $this->assertSame(HttpDriver::ENTITY_RESULT, $parseCode);
                    break;
            }
            $body .= $emitStruct[2]["body"];
        };

        $client = new Client;
        $client->options = new Options;
        $client->options->ioGranularity = 1;
        $driver = new Http1Driver;
        $driver->setup($emitCallback, function(){});
        $parser = $driver->parser($client);
        $headers =
            "POST /post-endpoint HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "Content-Length: 4\r\n\r\n"
        ;
        $part1 = "1\r\n";
        $part2 = "2\r\n";
        $parser->send($headers);
        $parser->send($part1);
        $parser->send($part2);

        $this->assertSame(4, $invoked);
        $this->assertSame("1\r\n2", $body);
    }

    function testChunkedBodyParseEmit() {
        $msg =
            "POST /post-endpoint HTTP/1.0\r\n" .
            "Host: localhost\r\n" .
            "Transfer-Encoding: chunked\r\n" .
            "Cookie: cookie1\r\n" .
            "Cookie: cookie2\r\n" .
            "Content-Length: 10\r\n" .
            "\r\n" .
            "5\r\n" .
            "woot!\r\n" .
            "4\r\n" .
            "test\r\n" .
            "0\r\n\r\n"
        ;

        $expectedBody = "woot!test";
        $invoked = 0;
        $body = "";
        $emitCallback = function(...$emitStruct) use (&$invoked, &$body) {
            $invoked++;
            $body .= $emitStruct[2]["body"];
        };

        $client = new Client;
        $client->options = new Options;
        $client->options->ioGranularity = 1;
        $driver = new Http1Driver;
        $driver->setup($emitCallback, function(){});
        $parser = $driver->parser($client);

        for($i=0,$c=strlen($msg);$i<$c;$i++) {
            $parser->send($msg[$i]);
        }

        $this->assertSame(strlen($expectedBody) + 2, $invoked);
        $this->assertSame($expectedBody, $body);
    }

    function provideParsableRequests() {
        $return = [];

        // 0 --- basic request -------------------------------------------------------------------->

        $msg =
            "GET / HTTP/1.1" . "\r\n" .
            "Host: localhost" . "\r\n" .
            "\r\n"
        ;
        $trace = substr($msg, 0, -2);
        $expectations = [
            "trace"       => $trace,
            "protocol"    => "1.1",
            "method"      => "GET",
            "uri"         => "/",
            "headers"     => ["host" => ["localhost"]],
            "body"        => "",
            "invocations" => 1,
        ];

        $return[] = [$msg, $expectations];

        // 1 --- multi-headers -------------------------------------------------------------------->

        $msg =
            "POST /post-endpoint HTTP/1.0\r\n" .
            "Host: localhost\r\n" .
            "Cookie: cookie1\r\n" .
            "Cookie: cookie2\r\n" .
            "Content-Length: 3\r\n" .
            "\r\n" .
            "123"
        ;
        $trace = explode("\r\n", $msg);
        array_pop($trace);
        $trace = implode("\r\n", $trace);

        $headers = [
            "host" => ["localhost"],
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
            "invocations" => 3,
        ];

        $return[] = [$msg, $expectations];

        // 2 --- OPTIONS request ------------------------------------------------------------------>

        $msg = "OPTIONS * HTTP/1.0\r\n\r\n";
        $trace = substr($msg, 0, -2);

        $expectations = [
            "trace"       => $trace,
            "protocol"    => "1.0",
            "method"      => "OPTIONS",
            "uri"         => "*",
            "headers"     => [],
            "body"        => "",
            "invocations" => 1,
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
            "Content-Length: 5\r\n"
        ;

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
            "invocations" => 3,
        ];

        $return[] = [$msg, $expectations];

        // 4 --- chunked entity body -------------------------------------------------------------->

        $trace =
            "GET /test HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "Transfer-Encoding: chunked\r\n"
        ;
        $msg = $trace .
            "\r\n" .
            "5\r\n" .
            "woot!\r\n" .
            "4\r\n" .
            "test\r\n" .
            "0\r\n\r\n"
        ;

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
            "invocations" => 3,
        ];

        $return[] = [$msg, $expectations];

        // 5 --- chunked entity body with trailer headers ----------------------------------------->

        $trace =
            "GET /test HTTP/1.1\r\n" .
            "Host: localhost\r\n" .
            "Transfer-Encoding: chunked\r\n"
        ;
        $msg = $trace .
            "\r\n" .
            "5\r\n" .
            "woot!\r\n" .
            "4\r\n" .
            "test\r\n" .
            "0\r\n" .
            "My-Trailer: 42\r\n" .
            "\r\n"
        ;

        $headers = [
            "host" => ["localhost"],
            "transfer-encoding" => ["chunked"],
            "my-trailer" => ["42"],
        ];

        $expectations = [
            "trace"       => $trace,
            "protocol"    => "1.1",
            "method"      => "GET",
            "uri"         => "/test",
            "headers"     => $headers,
            "body"        => "woot!test",
            "invocations" => 3,
        ];

        $return[] = [$msg, $expectations];

        // x -------------------------------------------------------------------------------------->

        return $return;
    }

    function provideUnparsableRequests() {
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
            "\r\n"
        ;
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
            "\r\n"
        ;
        $errCode = 400;
        $errMsg = "Bad Request: multi-line headers deprecated by RFC 7230";
        $opts = [];
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 4 -------------------------------------------------------------------------------------->

        $msg =
            "GET /someurl.html HTTP/1.0\r\n" .
            "Host: \r\n\tlocalhost\r\n" .
            "X-My-Header: 42\r\n" .
            "\r\n"
        ;
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

    function testUpgradeBodySizeContentLength() {
        $invoked = 0;
        $parseResult = null;
        $body = "";
        $client = new Client;

        $emitCallback = function(...$emitStruct) use ($client, &$invoked, &$parseResult, &$body) {
            $client->pendingResponses = 1;
            $invoked++;
            list(, $resultCode, $parseResult, $errorStruct) = $emitStruct;
            $this->assertNull($errorStruct);
            if ($resultCode != HttpDriver::SIZE_WARNING) {
                $body .= $parseResult["body"];
            }
        };

        $client->options = new Options;
        $client->options->maxBodySize = 4;
        $client->readWatcher = Loop::defer(function(){}); // dummy watcher
        $driver = new Http1Driver;
        $driver->setup($emitCallback, function(){});
        $parser = $driver->parser($client);
        $ireq = new InternalRequest;
        $ireq->client = $client;
        $client->bodyEmitters = ["set"];
        $client->requestParser = $parser;
        $payload = "abcdefghijklmnopqrstuvwxyz";
        $data = "POST / HTTP/1.1\r\nHost:localhost\r\nContent-Length: 26\r\n\r\n$payload";
        $parser->send($data);
        
        $this->assertEquals(4, \strlen($body));
        $ireq->maxBodySize = 10;
        $driver->upgradeBodySize($ireq);
        $this->assertEquals(10, \strlen($body));
        $ireq->maxBodySize = 26;
        $driver->upgradeBodySize($ireq);
        $this->assertSame($payload, $body);
    }

    function testUpgradeBodySizeStream() {
        $invoked = 0;
        $parseResult = null;
        $body = "";
        $client = new Client;

        $emitCallback = function(...$emitStruct) use ($client, &$invoked, &$parseResult, &$body) {
            $client->pendingResponses = 1;
            $invoked++;
            list(, $resultCode, $parseResult, $errorStruct) = $emitStruct;
            $this->assertNull($errorStruct);
            if ($resultCode != HttpDriver::SIZE_WARNING) {
                $body .= $parseResult["body"];
            }
        };

        $client->options = new Options;
        $client->options->maxBodySize = 4;
        $client->readWatcher = Loop::defer(function(){}); // dummy watcher
        $driver = new Http1Driver;
        $driver->setup($emitCallback, function(){});
        $parser = $driver->parser($client);
        $ireq = new InternalRequest;
        $ireq->client = $client;
        $client->bodyEmitters = ["set"];
        $client->requestParser = $parser;
        $payload = "2\r\nab\r\n3\r\ncde\r\n5\r\nfghij\r\n10\r\nklmnopqrstuvwxyz\r\n0\r\n\r\n";
        $data = "POST / HTTP/1.1\r\nHost:localhost\r\nTransfer-Encoding: chunked\r\n\r\n$payload";
        $parser->send($data);

        $this->assertEquals(4, \strlen($body));
        $ireq->maxBodySize = 10;
        $driver->upgradeBodySize($ireq);
        $this->assertEquals(10, \strlen($body));
        $ireq->maxBodySize = 26;
        $driver->upgradeBodySize($ireq);
        $this->assertSame("abcdefghijklmnopqrstuvwxyz", $body);
    }

    function verifyWrite($input, $status, $headers, $data) {
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

    function testWriter() {
        $headers = ["test" => ["successful"]];
        $status = 200;
        $rawHeaders = $headers + [":status" => $status, ":reason" => "OK", ":aerys-entity-length" => "*", ":aerys-push" => ["/foo" => []]];
        $data = "foobar";

        $driver = new Http1Driver;
        $driver->setup(function(){$this->fail();}, function($client, $final = false) use (&$fin) { $fin = $final; });
        $client = new Client;
        $client->options = new Options;
        $client->remainingKeepAlives = PHP_INT_MAX;
        foreach (["keepAliveTimeout" => 60, "defaultContentType" => "text/html", "defaultTextCharset" => "utf-8", "deflateEnable" => false, "sendServerToken" => false] as $k => $v) {
            $client->options->$k = $v;
        }
        $ireq = new InternalRequest;
        $ireq->client = $client;
        $ireq->protocol = "1.1";
        $ireq->responseWriter = $driver->writer($ireq);
        $ireq->httpDate = "date";
        $ireq->method = "GET";
        $writer = \Aerys\responseCodec(\Aerys\responseFilter($driver->filters($ireq, []), $ireq), $ireq);
        $writer->send($rawHeaders);
        foreach (str_split($data) as $c) {
            $writer->send($c);
            $writer->send(false);
        }
        $writer->send(null);

        $this->assertTrue($fin);
        $this->verifyWrite($client->writeBuffer, $status, $headers + ["transfer-encoding" => ["chunked"], "link" => ["</foo>; rel=preload"], "content-type" => ["text/html; charset=utf-8"], "keep-alive" => ["timeout=60"], "date" => ["date"]], $data);
        $this->assertNotTrue($client->shouldClose);
    }

    function testHttp2Upgrade() {
        $ireq = new InternalRequest;
        $ireq->protocol = "1.1";
        $ireq->headers = ["upgrade" => ["h2c"], "http2-settings" => [strtr(base64_encode("somesettings"), "+/", "-_")], "host" => ["foo.bar"]];
        $ireq->responseWriter = (function() use (&$headers) {
            $headers = yield;
        })();
        $ireq->uriPath = "/path";
        $ireq->method = "GET";
        $ireq->client = new Client;
        $ireq->client->options = new Options;

        $driver = new Http1Driver;
        $http2 = new class implements HttpDriver {
            public function setup(callable $parseEmitter, callable $responseWriter) {}
            public function upgradeBodySize(InternalRequest $ireq) {}
            public function filters(InternalRequest $ireq, array $filters): array { return $filters; }
            public function writer(InternalRequest $ireq): \Generator { yield; }

            public $received = "";
            public function parser(Client $client): \Generator {
                while (1) {
                    $this->received .= yield;
                }
            }
        };
        (function() use ($http2) {
            $this->http2 = $http2;
        })->call($driver);
        $driver->filters($ireq, []);

        $this->assertEquals([
            ":status" => \Aerys\HTTP_STATUS["SWITCHING_PROTOCOLS"],
            ":reason" => "Switching Protocols",
            "connection" => ["Upgrade"],
            "upgrade" => ["h2c"]
        ], $headers);
        $this->assertEquals("", $http2->received);
    }

    function testNativeHttp2() {
        $driver = new Http1Driver;
        $http2 = new class implements HttpDriver {
            public function setup(callable $parseEmitter, callable $responseWriter) {}
            public function upgradeBodySize(InternalRequest $ireq) {}
            public function filters(InternalRequest $ireq, array $filters): array { return $filters; }
            public function writer(InternalRequest $ireq): \Generator { yield; }

            public $received;
            public function parser(Client $client): \Generator {
                while (1) {
                    $this->received .= yield;
                }
            }

        };
        (function() use ($http2) {
            $this->http2 = $http2;
        })->call($driver);

        $client = new Client;
        $client->options = new Options;
        $data = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\nbinary data";
        $driver->parser($client)->send($data);
        $this->assertEquals($data, $http2->received);

    }
}
