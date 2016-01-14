<?php

namespace Aerys\Test;

use Aerys\Client;
use Aerys\HPack;
use Aerys\Http2Driver;
use Aerys\InternalRequest;
use Aerys\Options;

class Http2DriverTest extends \PHPUnit_Framework_TestCase {

    function packFrame($data, $type, $flags, $stream = 0) {
        return substr(pack("N", \strlen($data)), 1, 3) . $type . $flags . pack("N", $stream) . $data;
    }

    function packHeader($headers, $hasBody = false, $stream = 1, $split = PHP_INT_MAX) {
        $data = "";
        $headers = (new HPack)->encode($headers);
        $all = str_split($headers, $split);
        if ($split != PHP_INT_MAX) {
            $flag = Http2Driver::PADDED;
            $len = 1;
            $all[0] = chr($len) . $all[0] . str_repeat("\0", $len);
        } else {
            $flag = Http2Driver::NOFLAG;
        }
        $end = array_pop($all);
        $type = Http2Driver::HEADERS;
        foreach ($all as $frame) {
            $data .= $this->packFrame($frame, $type, $flag, $stream);
            $type = Http2Driver::CONTINUATION;
            $flag = Http2Driver::NOFLAG;
        }
        return $data . $this->packFrame($end, $type, ($hasBody ? $flag : Http2Driver::END_STREAM | $flag) | Http2Driver::END_HEADERS, $stream);
    }

    /**
     * @dataProvider provideSimpleCases
     */
    function testSimpleCases($msg, $expectations) {
        $msg = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n$msg";

        $driver = new class($this) extends Http2Driver {
            public function __construct($test) { $this->test = $test; }
            protected function writeFrame(Client $client, $data, $type, $flags, $stream = 0) {
                if ($type == Http2Driver::RST_STREAM || $type == Http2Driver::GOAWAY) {
                    $this->test->fail("RST_STREAM or GOAWAY frame received");
                }
            }
        };

        $emitCallback = function($emitStruct) use (&$client, &$invoked, &$parseResult, &$body) {
            list($resultCode, $tmpResult, $errorStruct) = $emitStruct;
            if (!$invoked++) {
                $parseResult = $tmpResult;
            }
            $this->assertNull($errorStruct);
            $body .= $tmpResult["body"];
            $client->bodyPromisors[$tmpResult["id"]] = true; // is used to verify whether headers were sent
        };
        $driver->setup($emitCallback, function(){});

        for ($mode = 0; $mode <= 1; $mode++) {
            $invoked = 0;
            $parseResult = null;
            $body = "";

            $client = new Client;
            $client->options = new Options;
            $parser = $driver->parser($client);

            if ($mode == 1) {
                for ($i = 0, $c = strlen($msg); $i < $c; $i++) {
                    $parser->send($msg[$i]);
                }
            } else {
                $parser->send($msg);
            }

            foreach ($parseResult["headers"] as $header => $_) {
                if ($header[0] == ":") {
                    unset($parseResult["headers"][$header]);
                }
            }

            $this->assertSame($expectations["invocations"], $invoked, "invocations mismatch");
            $this->assertSame($expectations["protocol"], $parseResult["protocol"], "protocol mismatch");
            $this->assertSame($expectations["method"], $parseResult["method"], "method mismatch");
            $this->assertSame($expectations["uri"], $parseResult["uri"], "uri mismatch");
            $this->assertSame($expectations["headers"], $parseResult["headers"], "headers mismatch");
            $this->assertSame($expectations["body"], $body, "body mismatch");
        }
    }

    function provideSimpleCases() {
        // 0 --- basic request -------------------------------------------------------------------->

        $headers = [
            ":authority" => "localhost",
            ":path" => "/",
            ":scheme" => "http",
            ":method" => "GET",
            "test" => "successful"
        ];
        $msg = $this->packFrame(pack("N", 100), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG);
        $msg .= $this->packHeader($headers);

        $expectations = [
            "protocol"    => "2.0",
            "method"      => "GET",
            "uri"         => "http://localhost/",
            "headers"     => ["test" => ["successful"]],
            "body"        => "",
            "invocations" => 1
        ];

        $return[] = [$msg, $expectations];

        // 1 --- request with partial (continuation) frames --------------------------------------->

        $msg = $this->packFrame(pack("N", 100), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG);
        $msg .= $this->packHeader($headers, true, 1, 1);
        $msg .= $this->packFrame("a", Http2Driver::DATA, Http2Driver::NOFLAG, 1);
        $msg .= $this->packFrame("", Http2Driver::DATA, Http2Driver::NOFLAG, 1);
        $msg .= $this->packFrame("b", Http2Driver::DATA, Http2Driver::END_STREAM, 1);

        $expectations = [
            "protocol"    => "2.0",
            "method"      => "GET",
            "uri"         => "http://localhost/",
            "headers"     => ["test" => ["successful"]],
            "body"        => "ab",
            "invocations" => 3
        ];

        $return[] = [$msg, $expectations];

        return $return;
    }

    function setupDriver() {
        $driver = new class($this) extends Http2Driver {
            public $frames = [];
            public function __construct($test) { $this->test = $test; }
            protected function writeFrame(Client $client, $data, $type, $flags, $stream = 0) {
                if ($type == Http2Driver::RST_STREAM || $type == Http2Driver::GOAWAY) {
                    $this->test->fail("RST_STREAM or GOAWAY frame received");
                }
                if ($type == Http2Driver::WINDOW_UPDATE) {
                    return; // we don't test this as we always give a far too large window currently ;-)
                }
                $this->frames[] = [$data, $type, $flags, $stream];
            }
        };

        $driver->setup(function() {}, function(){});

        return $driver;
    }

    function testPingPong() {
        $driver = $this->setupDriver();

        $client = new Client;
        $client->options = new Options;
        $parser = $driver->parser($client);

        $parser->send("PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n");
        $driver->frames = []; // ignore settings and window updates...

        $parser->send($this->packFrame("blahbleh", Http2Driver::PING, Http2Driver::NOFLAG));

        $this->assertEquals([["blahbleh", Http2Driver::PING, Http2Driver::ACK, 0]], $driver->frames);
    }

    function testFlowControl() {
        $driver = $this->setupDriver();

        $client = new Client;
        $client->options = new Options;
        $parser = $driver->parser($client);

        $parser->send("PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n");

        foreach ($driver->frames as list($data, $type, $flags, $stream)) {
            $this->assertEquals(Http2Driver::SETTINGS, $type);
            $this->assertEquals(0, $stream);
        }
        $driver->frames = [];

        $parser->send($this->packFrame(pack("nN", Http2Driver::INITIAL_WINDOW_SIZE, 66000), Http2Driver::SETTINGS, Http2Driver::NOFLAG));
        $this->assertEquals(1, count($driver->frames));
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::SETTINGS, $type);
        $this->assertEquals(Http2Driver::ACK, $flags);
        $this->assertEquals("", $data);
        $this->assertEquals(0, $stream);

        $headers = [
            ":authority" => "localhost",
            ":path" => "/",
            ":scheme" => "http",
            ":method" => "GET",
            "test" => "successful"
        ];
        $parser->send($this->packHeader($headers));

        $ireq = new InternalRequest;
        $ireq->client = $client;
        $ireq->streamId = 1;
        $writer = $driver->writer($ireq);

        $writer->send([":status" => 200]);

        $this->assertEquals([(new HPack)->encode([":status" => 200]), Http2Driver::HEADERS, Http2Driver::END_HEADERS, 1], array_pop($driver->frames));

        $writer->send(str_repeat("_", 66002));
        $writer->send(null);

        $recv = "";
        foreach ($driver->frames as list($data, $type, $flags, $stream)) {
            $recv .= $data;
            $this->assertEquals(Http2Driver::DATA, $type);
            $this->assertEquals(Http2Driver::NOFLAG, $flags);
            $this->assertEquals(1, $stream);
        }
        $driver->frames = [];

        $this->assertEquals(65536, \strlen($recv)); // global window!!

        $parser->send($this->packFrame(pack("N", 464 /* until 66000 */), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG));

        $this->assertEquals(1, count($driver->frames));
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::NOFLAG, $flags);
        $this->assertEquals(464, \strlen($data));
        $this->assertEquals(1, $stream);


        $parser->send($this->packFrame(pack("N", 4), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG));
        $this->assertEquals(0, count($driver->frames)); // global window update alone must not trigger send

        $parser->send($this->packFrame(pack("N", 1), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG, 1));

        $this->assertEquals(1, count($driver->frames));
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::NOFLAG, $flags);
        $this->assertEquals("_", $data);
        $this->assertEquals(1, $stream);

        $parser->send($this->packFrame(pack("N", 1), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG, 1));

        $this->assertEquals(1, count($driver->frames));
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::END_STREAM, $flags);
        $this->assertEquals("_", $data);
        $this->assertEquals(1, $stream);

        $parser->send($this->packHeader($headers, false, 3));

        $ireq = new InternalRequest;
        $ireq->client = $client;
        $ireq->streamId = 3;
        $writer = $driver->writer($ireq);

        $writer->send([":status" => 201]);

        $this->assertEquals([(new HPack)->encode([":status" => 201]), Http2Driver::HEADERS, Http2Driver::END_HEADERS, 3], array_pop($driver->frames));

        $writer->send("**");

        $this->assertEquals(1, count($driver->frames));
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::NOFLAG, $flags);
        $this->assertEquals("**", $data);
        $this->assertEquals(3, $stream);

        $writer->send("*");
        $this->assertEquals(0, count($driver->frames)); // global window too small

        $parser->send($this->packFrame(pack("N", 1), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG));

        $this->assertEquals(1, count($driver->frames));
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::NOFLAG, $flags);
        $this->assertEquals("*", $data);
        $this->assertEquals(3, $stream);

        $writer->send(null);

        $this->assertEquals(1, count($driver->frames));
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::END_STREAM, $flags);
        $this->assertEquals("", $data);
        $this->assertEquals(3, $stream);
    }

}