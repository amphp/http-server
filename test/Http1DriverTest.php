<?php

namespace Aerys\Test;

use Aerys\Internal;
use Aerys\Internal\Client;
use Aerys\Internal\Http1Driver;
use Aerys\Internal\HttpDriver;
use Aerys\Options;
use Aerys\Response;
use Amp\Artax\Internal\Parser;
use Amp\ByteStream\InMemoryStream;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Uri\Uri;

class Http1DriverTest extends TestCase {
    const HTTP_HEADER_EMITTERS = HttpDriver::RESULT | HttpDriver::ENTITY_HEADERS;
    const HTTP_EMITTERS = self::HTTP_HEADER_EMITTERS | HttpDriver::ENTITY_PART | HttpDriver::ENTITY_RESULT;
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
        $driver->setup([self::HTTP_EMITTERS => $emitCallback, HttpDriver::ERROR => $errorCallback], $this->createCallback(0));
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
        $driver->setup([self::HTTP_EMITTERS => $emitCallback, HttpDriver::ERROR => $errorCallback], $this->createCallback(0));
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
        $invoked = 0;
        $parseResult = null;
        $body = "";

        $headerEmitCallback = function (...$emitStruct) use (&$invoked, &$ireq) {
            $invoked++;
            list($ireq) = $emitStruct;
        };
        $dataEmitCallback = function ($client, $bodyData = "") use (&$invoked, &$body) {
            $invoked++;
            $body .= $bodyData;
        };
        $invokedCallback = function () use (&$invoked) {
            $invoked++;
        };

        $client = new Client;
        $client->options = new Options;
        $driver = new Http1Driver;
        $driver->setup([
            self::HTTP_HEADER_EMITTERS => $headerEmitCallback,
            HttpDriver::ENTITY_PART => $dataEmitCallback,
            HttpDriver::ENTITY_RESULT => $invokedCallback,
            HttpDriver::ERROR => $this->createCallback(0),
        ], $this->createCallback(0));

        $parser = $driver->parser($client);
        $parser->send($msg);

        $this->assertSame($expectations["invocations"], $invoked, "invocations mismatch");
        $this->assertSame($expectations["trace"], $ireq->trace, "trace mismatch");
        $this->assertSame($expectations["protocol"], $ireq->protocol, "protocol mismatch");
        $this->assertSame($expectations["method"], $ireq->method, "method mismatch");
        $this->assertSame($expectations["uri"], $ireq->uri->getPath(), "uri mismatch");
        $this->assertSame($expectations["headers"], $ireq->headers, "headers mismatch");
        $this->assertSame($expectations["body"], $body, "body mismatch");
    }

    /**
     * @dataProvider provideParsableRequests
     */
    public function testIncrementalRequestParse($msg, $expectations) {
        $invoked = 0;
        $parseResult = null;
        $body = "";

        $headerEmitCallback = function (...$emitStruct) use (&$invoked, &$ireq) {
            $invoked++;
            list($ireq) = $emitStruct;
        };
        $dataEmitCallback = function ($client, $bodyData) use (&$invoked, &$body) {
            $invoked++;
            $body .= $bodyData;
        };
        $invokedCallback = function () use (&$invoked) {
            $invoked++;
        };

        $client = new Client;
        $client->options = new Options;
        $client->serverPort = 80;
        $driver = new Http1Driver;
        $driver->setup([
            self::HTTP_HEADER_EMITTERS => $headerEmitCallback,
            HttpDriver::ENTITY_PART => $dataEmitCallback,
            HttpDriver::ENTITY_RESULT => $invokedCallback,
            HttpDriver::ERROR => $this->createCallback(0),
        ], $this->createCallback(0));

        $parser = $driver->parser($client);
        for ($i = 0, $c = strlen($msg); $i < $c; $i++) {
            $parser->send($msg[$i]);
        }

        $this->assertSame($expectations["invocations"], $invoked, "invocations mismatch");
        $this->assertSame($expectations["trace"], $ireq->trace, "trace mismatch");
        $this->assertSame($expectations["protocol"], $ireq->protocol, "protocol mismatch");
        $this->assertSame($expectations["method"], $ireq->method, "method mismatch");
        $this->assertSame($expectations["uri"], $ireq->uri->getPath(), "uri mismatch");
        $this->assertSame($expectations["headers"], $ireq->headers, "headers mismatch");
        $this->assertSame($expectations["body"], $body, "body mismatch");
        $this->assertSame(80, $ireq->uri->getPort());
    }

    public function testIdentityBodyParseEmit() {
        $originalBody = "12345";
        $msg =
            "POST /post-endpoint HTTP/1.0\r\n" .
            "Host: localhost\r\n" .
            "Cookie: cookie1\r\n" .
            "Cookie: cookie2\r\n" .
            "Content-Length: 10\r\n" .
            "\r\n" .
            $originalBody;

        $invoked = 0;
        $body = "";

        $invokedCallback = function () use (&$invoked) {
            $invoked++;
        };
        $emitCallback = function ($client, $bodyData) use (&$invoked, &$body) {
            $invoked++;
            $body .= $bodyData;
        };

        $client = new Client;
        $client->options = new Options;
        $client->options->ioGranularity = 1;
        $driver = new Http1Driver;
        $driver->setup([HttpDriver::ENTITY_HEADERS | HttpDriver::ENTITY_RESULT => $invokedCallback, HttpDriver::ENTITY_PART => $emitCallback], $this->createCallback(0));
        $parser = $driver->parser($client);
        for ($i = 0, $c = strlen($msg); $i < $c; $i++) {
            $parser->send($msg[$i]);
        }

        // once for headers and once for each body byte
        $this->assertSame(strlen($originalBody) + 1, $invoked);
        $this->assertSame($originalBody, $body);
    }

    public function testStreamingBodyParseEmit() {
        $invoked = 0;
        $emitCallbacks = [
            HttpDriver::ENTITY_HEADERS => function ($ireq) use (&$invoked) {
                $this->assertSame(++$invoked, 1);
                $this->assertSame("localhost", $ireq->uri->getHost());
                $this->assertSame(1337, $ireq->uri->getPort());
            },
            HttpDriver::ENTITY_PART => function ($client, $body) use (&$invoked) {
                if (++$invoked == 2) {
                    $this->assertSame("1\r\n", $body);
                } else {
                    $this->assertSame($invoked, 3);
                    $this->assertSame("2", $body);
                }
            },
            HttpDriver::ENTITY_RESULT => function () use (&$invoked) {
                $this->assertSame(++$invoked, 4);
            },
        ];

        $client = new Client;
        $client->options = new Options;
        $client->options->ioGranularity = 1;
        $driver = new Http1Driver;
        $driver->setup($emitCallbacks, $this->createCallback(0));
        $parser = $driver->parser($client);
        $headers =
            "POST /post-endpoint HTTP/1.1\r\n" .
            "Host: localhost:1337\r\n" .
            "Content-Length: 4\r\n\r\n";
        $part1 = "1\r\n";
        $part2 = "2\r\n";
        $parser->send($headers);
        $parser->send($part1);
        $parser->send($part2);

        $this->assertSame(4, $invoked);
    }

    public function testChunkedBodyParseEmit() {
        $msg =
            "POST https://test.local:1337/post-endpoint HTTP/1.0\r\n" .
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
            "0\r\n\r\n";

        $expectedBody = "woot!test";
        $invoked = 0;
        $body = "";
        $emitCallbacks = [
            HttpDriver::ENTITY_HEADERS => function ($ireq) use (&$invoked) {
                $this->assertSame(++$invoked, 1);
                $this->assertSame("https", $ireq->uri->getScheme());
                $this->assertSame("test.local", $ireq->uri->getHost());
                $this->assertSame(1337, $ireq->uri->getPort());
            },
            HttpDriver::ENTITY_PART => function ($client, $bodyData) use (&$invoked, &$body) {
                $invoked++;
                $body .= $bodyData;
            },
            HttpDriver::ENTITY_RESULT => function () use (&$invoked) {
                ++$invoked;
            },
        ];


        $client = new Client;
        $client->options = new Options;
        $client->options->ioGranularity = 1;
        $driver = new Http1Driver;
        $driver->setup($emitCallbacks, $this->createCallback(0));
        $parser = $driver->parser($client);

        for ($i=0, $c=strlen($msg);$i<$c;$i++) {
            $parser->send($msg[$i]);
        }

        $this->assertSame(strlen($expectedBody) + 2, $invoked);
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
            "invocations" => 1,
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
            "invocations" => 3,
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
            "invocations" => 3,
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
            "invocations" => 3,
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
            "invocations" => 3,
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
        $invoked = 0;
        $parseResult = null;
        $body = "";
        $client = new Client;

        $emitCallbacks = [
            HttpDriver::ENTITY_HEADERS | HttpDriver::ENTITY_RESULT => function () use (&$invoked) {
                $invoked++;
            },
            HttpDriver::ENTITY_PART => function ($client, $bodyData) use (&$invoked, &$body) {
                $client->pendingResponses = 1;
                $invoked++;
                $body .= $bodyData;
            },
            HttpDriver::SIZE_WARNING => function () use (&$expectsWarning) {
                $this->assertTrue($expectsWarning);
                $expectsWarning = false;
            },
        ];

        $client->options = new Options;
        $client->options->maxBodySize = 4;
        $client->readWatcher = Loop::defer(function () {}); // dummy watcher
        $driver = new Http1Driver;
        $driver->setup($emitCallbacks, $this->createCallback(0));
        $parser = $driver->parser($client);
        $ireq = new Internal\ServerRequest;
        $ireq->client = $client;
        $client->bodyEmitters = ["set"];
        $client->requestParser = $parser;

        $expectsWarning = true;
        $parser->send($data);
        $this->assertFalse($expectsWarning);

        $this->assertEquals(4, \strlen($body));
        $ireq->maxBodySize = 10;

        $expectsWarning = true;
        $driver->upgradeBodySize($ireq);
        $this->assertFalse($expectsWarning);

        $this->assertEquals(10, \strlen($body));
        $ireq->maxBodySize = 26;

        $driver->upgradeBodySize($ireq);
        $this->assertSame($payload, $body);

        $this->assertSame($invoked, 5 /* headers + 3*part + result */);
    }

    public function provideUpgradeBodySizeData() {
        $body = "abcdefghijklmnopqrstuvwxyz";

        $payload = $body;
        $data = "POST / HTTP/1.1\r\nHost:localhost\r\nContent-Length: 26\r\n\r\n$payload";
        $return[] = [$data, $body];

        $payload = "2\r\nab\r\n3\r\ncde\r\n5\r\nfghij\r\n10\r\nklmnopqrstuvwxyz\r\n0\r\n\r\n";
        $data = "POST / HTTP/1.1\r\nHost:localhost\r\nTransfer-Encoding: chunked\r\n\r\n$payload";
        $return[] = [$data, $body];

        return $return;
    }

    public function testPipelinedRequests() {
        list($payloads, $results) = array_map(null, ...$this->provideUpgradeBodySizeData());

        $body = "";
        $partInvoked = 0;
        $client = new Client;

        $emitCallbacks = [
            HttpDriver::ENTITY_HEADERS | HttpDriver::ENTITY_RESULT => function () { },
            HttpDriver::ENTITY_PART => function ($client, $bodyData) use (&$partInvoked, &$body) {
                $client->pendingResponses++;
                $partInvoked++;
                $body = $bodyData;
            },
        ];

        $client->options = new Options;
        $client->readWatcher = Loop::defer(function () {}); // dummy watcher
        $driver = new Http1Driver;
        $driver->setup($emitCallbacks, function ($client, $final = false) {
            $client->writeBuffer = "";
            if ($final) {
                $client->pendingResponses--;
            }
        });

        $parser = $driver->parser($client);
        $client->requestParser = $parser;

        $getWriter = function () use ($client, $driver) {
            $ireq = new Internal\ServerRequest;
            $ireq->client = $client;
            $ireq->client->remainingRequests = \PHP_INT_MAX;
            $ireq->protocol = "1.1";
            $ireq->httpDate = "date";
            $ireq->method = "GET";
            return $driver->writer($ireq, new Response\EmptyResponse);
        };

        $parser->send($payloads[0] . $payloads[1]);

        $this->assertSame($results[0], $body);
        $this->assertSame(1 /* first req */, $partInvoked);

        $writer = $getWriter();
        $writer->send(null);

        $this->assertSame(2 /* second req */, $partInvoked);
        $this->assertSame($results[1], $body);

        $writer = $getWriter();
        $writer->send(null);

        $parser->send($payloads[0]);

        $this->assertSame($results[0], $body);
        $this->assertSame(3 /* third req */, $partInvoked);

        $writer = $getWriter();
        $writer->send(null);

        $this->assertSame(3 /* once per request */, $partInvoked);

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

        $driver = new Http1Driver;
        $driver->setup([], function (Client $client, string $data, bool $final = false) use (&$buffer, &$fin) {
            $buffer = $data;
            $fin = $final;
        });
        $client = new Client;
        $client->options = new Options;
        $client->remainingRequests = PHP_INT_MAX;
        foreach ([
            "connectionTimeout" => 60,
            "defaultContentType" => "text/plain",
            "defaultTextCharset" => "utf-8",
            "deflateEnable" => false,
            "sendServerToken" => false
        ] as $k => $v) {
            $client->options->$k = $v;
        }

        $ireq = new Internal\ServerRequest;
        $ireq->client = $client;
        $ireq->protocol = "1.1";
        $ireq->httpDate = "date";
        $ireq->method = "GET";

        $writer = $driver->writer($ireq, $response = new Response(new InMemoryStream, $headers)); // Use same body to set prop

        $response->push("/foo");

        foreach (str_split($data) as $c) {
            $writer->send($c);
        }
        $writer->send(null);

        $this->assertTrue($fin);
        $this->verifyWrite($buffer, $status, $headers + [
                "link" => ["</foo>; rel=preload"],
                "content-type" => ["text/plain; charset=utf-8"],
                "connection" => ["keep-alive"],
                "keep-alive" => ["timeout=60"],
                "date" => ["date"],
                "transfer-encoding" => ["chunked"],
            ], $data);
        $this->assertNotTrue($client->shouldClose);
    }

    public function testWriterAbortAfterHeaders() {
        $driver = new Http1Driver;
        $driver->setup([], function (Client $client, string $data, bool $final) use (&$invoked) {
            $this->assertTrue($final);
            $this->assertTrue($client->shouldClose);
            $expected = "HTTP/1.1 200 OK";
            $this->assertEquals($expected, \substr($data, 0, \strlen($expected)));
            $invoked = true;
        });

        $client = new Client;
        $client->options = new Options;
        $ireq = new Internal\ServerRequest;
        $ireq->client = $client;
        $ireq->protocol = "1.1";
        $writer = $driver->writer($ireq, new Response);

        $writer->send("foo");

        $this->assertNull($invoked);
        $writer->send(null);
        $this->assertTrue($invoked);
    }

    public function testHttp2Upgrade() {
        $ireq = new Internal\ServerRequest;
        $ireq->protocol = "1.1";
        $ireq->headers = ["upgrade" => ["h2c"], "http2-settings" => [strtr(base64_encode("somesettings"), "+/", "-_")], "host" => ["foo.bar"]];
        $ireq->uri = new Uri("http://localhost/path");
        $ireq->method = "GET";
        $ireq->client = new Client;
        $ireq->client->options = new Options;

        $responseWriter = function ($res) use (&$response) {
            $response = $res;
        };

        $driver = new Http1Driver;
        $driver->setup([], $this->createCallback(1));

        $http2 = new class implements HttpDriver {
            /** @var \Generator */
            private $responseWriter;
            public function setup(array $parseEmitters, callable $responseWriter) {
                $this->responseWriter = $responseWriter;
            }
            public function upgradeBodySize(Internal\ServerRequest $ireq) {
            }
            public function writer(Internal\ServerRequest $ireq, Response $response): \Generator {
                ($this->responseWriter)($response);
                return (function () { yield; })();
            }

            public $received = "";
            public function parser(Client $client): \Generator {
                while (1) {
                    $this->received .= yield;
                }
            }
        };
        $http2->setup([], $responseWriter);

        // Set HTTP/2 driver with bound closure.
        (function () use ($http2) {
            $this->http2 = $http2;
        })->call($driver);

        $writer = $driver->writer($ireq, $sent = new Response\EmptyResponse);

        $this->assertSame($sent, $response);

        $this->assertEquals("", $http2->received);
    }

    public function testNativeHttp2() {
        $driver = new Http1Driver;
        $http2 = new class implements HttpDriver {
            public function setup(array $parseEmitters, callable $responseWriter) {
            }
            public function upgradeBodySize(Internal\ServerRequest $ireq) {
            }
            public function writer(Internal\ServerRequest $ireq, Response $response): \Generator {
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
