<?php

namespace Aerys\Test;

use Aerys\Client;
use Aerys\Http2Driver;
use Aerys\Internal\HPack;
use Aerys\NullBody;
use Aerys\Options;
use Aerys\Request;
use Aerys\Response;
use Aerys\TimeReference;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use Amp\Success;
use Amp\Uri\Uri;

class Http2DriverTest extends TestCase {
    public static function packFrame($data, $type, $flags, $stream = 0) {
        return substr(pack("N", \strlen($data)), 1, 3) . $type . $flags . pack("N", $stream) . $data;
    }

    public static function packHeader($headers, $hasBody = false, $stream = 1, $split = PHP_INT_MAX) {
        $data = "";
        $headers = HPack::encode($headers);
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
            $data .= self::packFrame($frame, $type, $flag, $stream);
            $type = Http2Driver::CONTINUATION;
            $flag = Http2Driver::NOFLAG;
        }
        return $data . self::packFrame($end, $type, ($hasBody ? $flag : Http2Driver::END_STREAM | $flag) | Http2Driver::END_HEADERS, $stream);
    }

    /**
     * @dataProvider provideSimpleCases
     */
    public function testSimpleCases($msg, $expectations) {
        $msg = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n$msg";

        $driver = $this->setupDriver(function (Request $req) use (&$request) {
            $request = $req;
        });

        for ($mode = 0; $mode <= 1; $mode++) {
            $parseResult = null;

            $parser = $driver->parser();

            if ($mode === 1) {
                for ($i = 0, $length = \strlen($msg); $i < $length; $i++) {
                    $parser->send($msg[$i]);
                }
            } else {
                $parser->send($msg);
            }

            $this->assertInstanceOf(Request::class, $request);

            /** @var \Aerys\Request $request */
            $body = Promise\wait($request->getBody()->buffer());

            $headers = $request->getHeaders();
            foreach ($headers as $header => $value) {
                if ($header[0] === ":") {
                    unset($headers[$header]);
                }
            }

            $this->assertSame($expectations["protocol"], $request->getProtocolVersion(), "protocol mismatch");
            $this->assertSame($expectations["method"], $request->getMethod(), "method mismatch");
            $this->assertSame($expectations["uri"], $request->getUri()->getPath(), "uri mismatch");
            $this->assertSame($expectations["headers"], $headers, "headers mismatch");
            $this->assertSame($expectations["port"] ?? 80, $request->getUri()->getPort(), "uriPort mismatch");
            $this->assertSame($expectations["host"], $request->getUri()->getHost(), "uriHost mismatch");
            $this->assertSame($expectations["body"], $body, "body mismatch");
        }
    }

    public function provideSimpleCases() {
        // 0 --- basic request -------------------------------------------------------------------->

        $headers = [
            ":authority" => "localhost:8888",
            ":path" => "/foo",
            ":scheme" => "http",
            ":method" => "GET",
            "test" => "successful"
        ];
        $msg = self::packFrame(pack("N", 100), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG);
        $msg .= self::packHeader($headers);

        $expectations = [
            "protocol"    => "2.0",
            "method"      => "GET",
            "uri"         => "/foo",
            "host"        => "localhost",
            "port"        => 8888,
            "headers"     => ["test" => ["successful"]],
            "body"        => "",
            "invocations" => 1
        ];

        $return[] = [$msg, $expectations];

        // 1 --- request with partial (continuation) frames --------------------------------------->

        $headers[":authority"] = "localhost";

        $msg = self::packFrame(pack("N", 100), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG);
        $msg .= self::packHeader($headers, true, 1, 1);
        $msg .= self::packFrame("a", Http2Driver::DATA, Http2Driver::NOFLAG, 1);
        $msg .= self::packFrame("", Http2Driver::DATA, Http2Driver::NOFLAG, 1);
        $msg .= self::packFrame("b", Http2Driver::DATA, Http2Driver::END_STREAM, 1);

        $expectations = [
            "protocol"    => "2.0",
            "method"      => "GET",
            "uri"         => "/foo",
            "host"        => "localhost",
            "headers"     => ["test" => ["successful"]],
            "body"        => "ab",
            "invocations" => 4 /* header + 2 * individual data + end */
        ];

        $return[] = [$msg, $expectations];

        return $return;
    }

    public function setupDriver(callable $onMessage = null, callable $writer = null, Options $options = null) {
        $driver = new class($this, $options ?? new Options, $this->createMock(TimeReference::class)) extends Http2Driver {
            public $frames = [];

            /** @var TestCase */
            private $test;

            public function __construct($test, $options, $timeReference) {
                parent::__construct($options, $timeReference);
                $this->test = $test;
            }

            protected function writeFrame(string $data, string $type, string $flags, int $stream = 0): Promise {
                if ($type === Http2Driver::RST_STREAM || $type === Http2Driver::GOAWAY) {
                    $this->test->fail("RST_STREAM or GOAWAY frame received");
                }

                if ($type === Http2Driver::WINDOW_UPDATE) {
                    return new Success; // we don't test this as we always give a far too large window currently ;-)
                }

                $this->frames[] = [$data, $type, $flags, $stream];

                return new Success;
            }
        };

        $driver->setup(
            $this->createMock(Client::class),
            $onMessage ?? function () {},
            $writer ?? $this->createCallback(0)
        );

        return $driver;
    }

    public function testWriterAbortAfterHeaders() {
        $buffer = "";
        $options = new Options;
        $driver = new Http2Driver($options, $this->createMock(TimeReference::class));
        $driver->setup(
            $this->createMock(Client::class),
            $this->createCallback(0),
            function (string $data, bool $close = false) use (&$buffer) {
                // HTTP/2 shall only reset streams, not abort the connection
                $this->assertFalse($close);
                $buffer .= $data;
                return new Success;
            }
        );
        $parser = $driver->parser("", true); // Simulate upgrade request.

        $parser->send("PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n");

        $request = new Request($this->createMock(Client::class), "GET", new Uri("/"), [], null, "/", "2.0");

        $writer = $driver->writer(new Response, $request);

        $writer->send("foo");

        unset($writer);

        $data = self::packFrame(pack(
            "nNnNnN",
            Http2Driver::INITIAL_WINDOW_SIZE,
            $options->getMaxBodySize() + 256,
            Http2Driver::MAX_CONCURRENT_STREAMS,
            $options->getMaxConcurrentStreams(),
            Http2Driver::MAX_HEADER_LIST_SIZE,
            $options->getMaxHeaderSize()
        ), Http2Driver::SETTINGS, Http2Driver::NOFLAG, 0);

        $data .= self::packFrame(HPack::encode([
            ":status" => 200,
            "content-length" => ["0"],
            "date" => [""],
        ]), Http2Driver::HEADERS, Http2Driver::END_HEADERS, 1);

        $data .= self::packFrame("foo", Http2Driver::DATA, Http2Driver::NOFLAG, 1);
        $data .= self::packFrame(pack("N", Http2Driver::INTERNAL_ERROR), Http2Driver::RST_STREAM, Http2Driver::NOFLAG, 1);

        $this->assertEquals($data, $buffer);
    }

    public function testPingPong() {
        $driver = $this->setupDriver();

        $parser = $driver->parser();

        $parser->send("PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n");
        $driver->frames = []; // ignore settings and window updates...

        $parser->send(self::packFrame("blahbleh", Http2Driver::PING, Http2Driver::NOFLAG));

        $this->assertEquals([["blahbleh", Http2Driver::PING, Http2Driver::ACK, 0]], $driver->frames);
    }

    public function testFlowControl() {
        $driver = $this->setupDriver(function (Request $read) use (&$request) {
            $request = $read;
        }, null, (new Options)->withOutputBufferSize(1));

        $parser = $driver->parser();

        $parser->send("PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n");

        foreach ($driver->frames as list($data, $type, $flags, $stream)) {
            $this->assertEquals(Http2Driver::SETTINGS, $type);
            $this->assertEquals(0, $stream);
        }
        $driver->frames = [];

        $parser->send(self::packFrame(pack("nN", Http2Driver::INITIAL_WINDOW_SIZE, 66000), Http2Driver::SETTINGS, Http2Driver::NOFLAG));
        $this->assertCount(1, $driver->frames);
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
        $parser->send(self::packHeader($headers, false, 1));

        // $onMessage callback should be invoked.
        $this->assertInstanceOf(Request::class, $request);

        $writer = $driver->writer(new Response(null, ["content-type" => "text/html; charset=utf-8"]), $request);
        $writer->valid(); // Start writer.

        $this->assertEquals([HPack::encode([
            ":status" => 200,
            "content-type" => ["text/html; charset=utf-8"],
            "content-length" => ["0"],
            "date" => [""],
        ]), Http2Driver::HEADERS, Http2Driver::END_HEADERS, 1], array_pop($driver->frames));

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

        $parser->send(self::packFrame(pack("N", 464 /* until 66000 */), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG));

        $this->assertCount(1, $driver->frames);
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::NOFLAG, $flags);
        $this->assertEquals(464, \strlen($data));
        $this->assertEquals(1, $stream);

        $parser->send(self::packFrame(pack("N", 4), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG));
        $this->assertCount(0, $driver->frames); // global window update alone must not trigger send

        $parser->send(self::packFrame(pack("N", 1), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG, 1));

        $this->assertCount(1, $driver->frames);
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::NOFLAG, $flags);
        $this->assertEquals("_", $data);
        $this->assertEquals(1, $stream);

        $parser->send(self::packFrame(pack("N", 1), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG, 1));

        $this->assertCount(1, $driver->frames);
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::END_STREAM, $flags);
        $this->assertEquals("_", $data);
        $this->assertEquals(1, $stream);

        $parser->send(self::packHeader($headers, false, 3));

        // $onMessage callback should be invoked.
        $this->assertInstanceOf(Request::class, $request);

        $writer = $driver->writer(new Response(null, ["content-type" => "text/html; charset=utf-8"]), $request);
        $writer->valid(); // Start writer.

        $this->assertEquals([HPack::encode([
            ":status" => 200,
            "content-type" => ["text/html; charset=utf-8"],
            "content-length" => ["0"],
            "date" => [""],
        ]), Http2Driver::HEADERS, Http2Driver::END_HEADERS, 3], array_pop($driver->frames));

        $writer->send("**");

        $this->assertCount(1, $driver->frames);
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::NOFLAG, $flags);
        $this->assertEquals("**", $data);
        $this->assertEquals(3, $stream);

        $writer->send("*");
        $this->assertCount(0, $driver->frames); // global window too small

        $parser->send(self::packFrame(pack("N", 1), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG));

        $this->assertCount(1, $driver->frames);
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::NOFLAG, $flags);
        $this->assertEquals("*", $data);
        $this->assertEquals(3, $stream);

        $writer->send(null);

        $this->assertCount(1, $driver->frames);
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::END_STREAM, $flags);
        $this->assertEquals("", $data);
        $this->assertEquals(3, $stream);
    }
}
