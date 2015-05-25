<?php

namespace Aerys\Test;

use Aerys\Rfc7230Server;

class Rfc7230ServerTest extends \PHPUnit_Framework_TestCase {

    /**
     * @dataProvider provideUnparsableRequests
     */
    public function testBadRequestBufferedParse($unparsable, $errCode, $errMsg, $opts) {
        $invoked = 0;
        $resultCode;
        $parseResult;
        $errorStruct;

        $emitCallback = function($emitStruct) use (&$invoked, &$resultCode, &$parseResult, &$errorStruct) {
            $invoked++;
            list($resultCode, $parseResult, $errorStruct) = $emitStruct;
        };

        $parser = (new \ReflectionClass(Rfc7230Server::class))->newInstanceWithoutConstructor()->parser($emitCallback, $opts);
        $parser->send($unparsable);

        $this->assertTrue($invoked > 0);
        $this->assertSame(Rfc7230Server::ERROR, $resultCode);
        list($actualErrCode, $actualErrMsg) = $errorStruct;
        $this->assertSame($errMsg, $actualErrMsg);
        $this->assertSame($errCode, $actualErrCode);
    }

    /**
     * @dataProvider provideUnparsableRequests
     */
    public function testBadRequestIncrementalParse($unparsable, $errCode, $errMsg, $opts) {
        $invoked = 0;
        $resultCode;
        $parseResult;
        $errorStruct;

        $emitCallback = function($emitStruct) use (&$invoked, &$resultCode, &$parseResult, &$errorStruct) {
            $invoked++;
            list($resultCode, $parseResult, $errorStruct) = $emitStruct;
        };

        $parser = (new \ReflectionClass(Rfc7230Server::class))->newInstanceWithoutConstructor()->parser($emitCallback, $opts);

        for ($i=0, $c=strlen($unparsable); $i<$c; $i++) {
            $parser->send($unparsable[$i]);
            if ($errorStruct) {
                break;
            }
        }

        $this->assertTrue($invoked > 0);
        $this->assertSame(Rfc7230Server::ERROR, $resultCode);
        list($actualErrCode, $actualErrMsg) = $errorStruct;
        $this->assertSame($errMsg, $actualErrMsg);
        $this->assertSame($errCode, $actualErrCode);
    }

    /**
     * @dataProvider provideParsableRequests
     */
    public function testBufferedRequestParse($msg, $expectations) {
        $invoked = 0;
        $parseResult;
        $body = "";

        $emitCallback = function($emitStruct) use (&$invoked, &$parseResult, &$body) {
            $invoked++;
            list($resultCode, $parseResult, $errorStruct) = $emitStruct;
            $this->assertNull($errorStruct);
            $body .= $parseResult["body"];
        };

        $parser = (new \ReflectionClass(Rfc7230Server::class))->newInstanceWithoutConstructor()->parser($emitCallback);
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
    public function testIncrementalRequestParse($msg, $expectations) {
        $invoked = 0;
        $parseResult;
        $body = "";

        $emitCallback = function($emitStruct) use (&$invoked, &$parseResult, &$body) {
            $invoked++;
            list($resultCode, $parseResult, $errorStruct) = $emitStruct;
            $this->assertNull($errorStruct);
            $body .= $parseResult["body"];
        };

        $parser = (new \ReflectionClass(Rfc7230Server::class))->newInstanceWithoutConstructor()->parser($emitCallback);
        for($i=0,$c=strlen($msg);$i<$c;$i++) {
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

    public function testIdentityBodyParseEmit() {
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

        $emitCallback = function($emitStruct) use (&$invoked, &$body) {
            $invoked++;
            $body .= $emitStruct[1]["body"];
        };

        $parser = (new \ReflectionClass(Rfc7230Server::class))->newInstanceWithoutConstructor()->parser($emitCallback, ["body_emit_size" => 1]);

        for($i=0,$c=strlen($msg);$i<$c;$i++) {
            $parser->send($msg[$i]);
        }

        // once for headers and once for each body byte
        $this->assertSame(strlen($originalBody) + 1, $invoked);
        $this->assertSame($originalBody, $body);
    }

    public function testStreamingBodyParseEmit() {
        $body = "";
        $invoked = 0;
        $emitCallback = function($emitStruct) use (&$invoked, &$body) {
            $invoked++;
            list($parseCode, $parseResultArr) = $emitStruct;
            switch($invoked) {
                case 1:
                    $this->assertSame(Rfc7230Server::ENTITY_HEADERS, $parseCode);
                    $this->assertSame("", $parseResultArr["body"]);
                    break;
                case 2:
                    $this->assertSame(Rfc7230Server::ENTITY_PART, $parseCode);
                    $this->assertSame("1\r\n", $parseResultArr["body"]);
                    break;
                case 3:
                    $this->assertSame(Rfc7230Server::ENTITY_PART, $parseCode);
                    $this->assertSame("2", $parseResultArr["body"]);
                    break;
                case 4:
                    $this->assertSame(Rfc7230Server::ENTITY_RESULT, $parseCode);
                    break;
            }
            $body .= $emitStruct[1]["body"];
        };

        $parser = (new \ReflectionClass(Rfc7230Server::class))->newInstanceWithoutConstructor()->parser($emitCallback, ["body_emit_size" => 1]);
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

    public function testChunkedBodyParseEmit() {
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
        $emitCallback = function($emitStruct) use (&$invoked, &$body) {
            $invoked++;
            $body .= $emitStruct[1]["body"];
        };

        $parser = (new \ReflectionClass(Rfc7230Server::class))->newInstanceWithoutConstructor()->parser($emitCallback, ["body_emit_size" => 1]);

        for($i=0,$c=strlen($msg);$i<$c;$i++) {
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
            "\r\n"
        ;
        $trace = substr($msg, 0, -2);
        $expectations = [
            "trace"       => $trace,
            "protocol"    => "1.1",
            "method"      => "GET",
            "uri"         => "/",
            "headers"     => ["HOST" => ["localhost"]],
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
            "HOST" => ["localhost"],
            "COOKIE" => ["cookie1", "cookie2"],
            "CONTENT-LENGTH" => ["3"]
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
            "HOST" => ["localhost"],
            "CONNECTION" => ["keep-alive"],
            "USER-AGENT" => ["Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.11 (KHTML, like Gecko) Chrome/23.0.1271.97 Safari/537.11"],
            "ACCEPT" => ["text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"],
            "ACCEPT-ENCODING" => ["gzip,deflate,sdch"],
            "ACCEPT-LANGUAGE" => ["en-US,en;q=0.8"],
            "ACCEPT-CHARSET" => ["ISO-8859-1,utf-8;q=0.7,*;q=0.3"],
            "CONTENT-LENGTH" => ["5"]
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
            "HOST" => ["localhost"],
            "TRANSFER-ENCODING" => ["chunked"],
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
            "HOST" => ["localhost"],
            "TRANSFER-ENCODING" => ["chunked"],
            "MY-TRAILER" => ["42"],
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

        $msg = "GET / HTTP/0.9\r\n\r\n";
        $errCode = 505;
        $errMsg = "Protocol not supported";
        $opts = [];
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 3 -------------------------------------------------------------------------------------->

        $msg =
            "POST /someurl.html HTTP/1.0\r\n" .
            "Host: localhost\r\n" .
            "Content-Length: 43\r\n" .
            "\r\nThis should error because it's too long ..."
        ;
        $errCode = 400;
        $errMsg = "Bad request: entity too large";
        $opts = ["max_body_size" => 1];
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 4 -------------------------------------------------------------------------------------->

        $msg =
            "GET /someurl.html HTTP/1.0\r\n" .
            "Host: localhost\r\n" .
            "X-My-Header: " . str_repeat("x", 1024) . "r\n" .
            "\r\n"
        ;
        $errCode = 431;
        $errMsg = "Bad Request: header size violation";
        $opts = ["max_header_size" => 128];
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 5 -------------------------------------------------------------------------------------->

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

        // 6 -------------------------------------------------------------------------------------->

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

        // 7 -------------------------------------------------------------------------------------->

        $msg = "GET / HTTP/2.0\r\n\r\n";
        $errCode = 400;
        $errMsg = "Bad Request: invalid protocol";
        $opts = [];
        $return[] = [$msg, $errCode, $errMsg, $opts];

        // 8 -------------------------------------------------------------------------------------->

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
}
