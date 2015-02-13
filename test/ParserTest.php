<?php

namespace Aerys\Test;

use Aerys\Parser;
use Aerys\ParseRequestContext;

class ParserTest extends \PHPUnit_Framework_TestCase {

    /**
     * @dataProvider provideUnparsableRequests
     */
    public function testBadRequestBufferedParse($unparsable, $errCode, $errMsg, $opts) {
        $rpc = new ParseRequestContext;

        foreach ($opts as $key => $value) {
            $rpc->{$key} = $value;
        }

        $invoked = 0;
        $resultCode;
        $parseResult;
        $errorStruct;

        $rpc->emitCallback = function($emitStruct) use (&$invoked, &$resultCode, &$parseResult, &$errorStruct) {
            $invoked++;
            list($resultCode, $parseResult, $errorStruct) = $emitStruct;
        };

        Parser::parseRequest($unparsable, $rpc);

        $this->assertTrue($invoked > 0);
        $this->assertSame(Parser::ERROR, $resultCode);
        list($actualErrCode, $actualErrMsg) = $errorStruct;
        $this->assertSame($errMsg, $actualErrMsg);
        $this->assertSame($errCode, $actualErrCode);
    }

    /**
     * @dataProvider provideUnparsableRequests
     */
    public function testBadRequestIncrementalParse($unparsable, $errCode, $errMsg, $opts) {
        $rpc = new ParseRequestContext;

        foreach ($opts as $key => $value) {
            $rpc->{$key} = $value;
        }

        $invoked = 0;
        $resultCode;
        $parseResult;
        $errorStruct;

        $rpc->emitCallback = function($emitStruct) use (&$invoked, &$resultCode, &$parseResult, &$errorStruct) {
            $invoked++;
            list($resultCode, $parseResult, $errorStruct) = $emitStruct;
        };

        for ($i=0, $c=strlen($unparsable); $i<$c; $i++) {
            Parser::parseRequest($unparsable[$i], $rpc);
            if ($errorStruct) {
                break;
            }
        }

        $this->assertTrue($invoked > 0);
        $this->assertSame(Parser::ERROR, $resultCode);
        list($actualErrCode, $actualErrMsg) = $errorStruct;
        $this->assertSame($errMsg, $actualErrMsg);
        $this->assertSame($errCode, $actualErrCode);
    }

    /**
     * @dataProvider provideParsableRequests
     */
    public function testBufferedRequestParse($msg, $expectations) {
        $rpc = new ParseRequestContext;

        $invoked = 0;
        $parseResult;
        $body = "";

        $rpc->emitCallback = function($emitStruct) use (&$invoked, &$parseResult, &$body) {
            $invoked++;
            list($resultCode, $parseResult, $errorStruct) = $emitStruct;
            $this->assertNull($errorStruct);
            $body .= $parseResult["body"];
        };

        Parser::parseRequest($msg, $rpc);

        $this->assertTrue($invoked > 0);
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
        $rpc = new ParseRequestContext;

        $invoked = 0;
        $parseResult;
        $body = "";

        $rpc->emitCallback = function($emitStruct) use (&$invoked, &$parseResult, &$body) {
            $invoked++;
            list($resultCode, $parseResult, $errorStruct) = $emitStruct;
            $this->assertNull($errorStruct);
            $body .= $parseResult["body"];
        };

        for($i=0,$c=strlen($msg);$i<$c;$i++) {
            Parser::parseRequest($msg[$i], $rpc);
        }

        $this->assertTrue($invoked > 0);
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
        $rpc = new ParseRequestContext;
        $rpc->bodyEmitSize = 1;
        $rpc->emitCallback = function($emitStruct) use (&$invoked, &$body) {
            $invoked++;
            $body .= $emitStruct[1]["body"];
        };

        for($i=0,$c=strlen($msg);$i<$c;$i++) {
            Parser::parseRequest($msg[$i], $rpc);
        }

        // once for headers and once for each body byte
        $this->assertSame(strlen($originalBody) + 1, $invoked);
        $this->assertSame($originalBody, $body);
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
        $rpc = new ParseRequestContext;
        $rpc->bodyEmitSize = 1;
        $rpc->emitCallback = function($emitStruct) use (&$invoked, &$body) {
            $invoked++;
            $body .= $emitStruct[1]["body"];
        };

        for($i=0,$c=strlen($msg);$i<$c;$i++) {
            Parser::parseRequest($msg[$i], $rpc);
        }

        $this->assertSame(strlen($expectedBody), $invoked);
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
        $opts = ["maxBodySize" => 1];
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
        $opts = ["maxHeaderSize" => 128];
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
