<?php

namespace Amp\Http\Server\Test;

use Amp\ByteStream\IteratorStream;
use Amp\Emitter;
use Amp\Http\HPack;
use Amp\Http\Server\Client;
use Amp\Http\Server\Http2Driver;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\TimeReference;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use Amp\Success;
use League\Uri;

class Http2DriverTest extends TestCase {
    public static function packFrame($data, $type, $flags, $stream = 0) {
        return substr(pack("N", \strlen($data)), 1, 3) . $type . $flags . pack("N", $stream) . $data;
    }

    public static function packHeader($headers, $continue = false, $stream = 1, $split = PHP_INT_MAX) {
        $data = "";
        $hpack = new HPack;
        $headers = $hpack->encode($headers);
        $all = str_split($headers, $split);
        if ($split !== PHP_INT_MAX) {
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

        $flags = ($continue ? $flag : Http2Driver::END_STREAM | $flag) | Http2Driver::END_HEADERS;

        return $data . self::packFrame($end, $type, $flags, $stream);
    }

    /**
     * @dataProvider provideSimpleCases
     */
    public function testSimpleCases($msg, $expectations) {
        $msg = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n$msg";

        for ($mode = 0; $mode <= 1; $mode++) {
            list($driver, $parser) = $this->setupDriver(function (Request $req) use (&$request, &$parser) {
                $request = $req;
            });

            $parseResult = null;

            if ($mode === 1) {
                for ($i = 0, $length = \strlen($msg); $i < $length; $i++) {
                    $promise = $parser->send($msg[$i]);
                }
            } else {
                $promise = $parser->send($msg);
            }

            while ($promise instanceof Promise) {
                $promise = $parser->send("");
            }

            $this->assertInstanceOf(Request::class, $request);

            /** @var \Amp\Http\Server\Request $request */
            $body = Promise\wait($request->getBody()->buffer());
            $trailers = Promise\wait($request->getBody()->getTrailers());

            $headers = $request->getHeaders();
            foreach ($headers as $header => $value) {
                if ($header[0] === ":") {
                    unset($headers[$header]);
                }
            }

            $defaultPort = $request->getUri()->getScheme() === "https" ? 443 : 80;
            $this->assertSame($expectations["protocol"], $request->getProtocolVersion(), "protocol mismatch");
            $this->assertSame($expectations["method"], $request->getMethod(), "method mismatch");
            $this->assertSame($expectations["uri"], $request->getUri()->getPath(), "uri mismatch");
            $this->assertSame($expectations["headers"], $headers, "headers mismatch");
            $this->assertSame($expectations["port"] ?? 80, $request->getUri()->getPort() ?: $defaultPort, "uriPort mismatch");
            $this->assertSame($expectations["host"], $request->getUri()->getHost(), "uriHost mismatch");
            $this->assertSame($expectations["body"], $body, "body mismatch");
            $this->assertSame($expectations["trailers"] ?? [], $trailers->getHeaders());
        }
    }

    public function provideSimpleCases() {
        // 0 --- basic request -------------------------------------------------------------------->

        $headers = [
            ":authority" => ["localhost:8888"],
            ":path" => ["/foo"],
            ":scheme" => ["http"],
            ":method" => ["GET"],
            "test" => ["successful"]
        ];
        $msg = self::packFrame(pack("N", 100), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG);
        $msg .= self::packHeader($headers, false, 1);

        $expectations = [
            "protocol"    => "2.0",
            "method"      => "GET",
            "uri"         => "/foo",
            "host"        => "localhost",
            "port"        => 8888,
            "headers"     => ["test" => ["successful"]],
            "body"        => "",
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
        ];

        $return[] = [$msg, $expectations];

        // 2 --- request trailing headers --------------------------------------------------------->

        $headers = [
            ":authority" => ["localhost"],
            ":path" => ["/foo"],
            ":scheme" => ["http"],
            ":method" => ["GET"],
            "trailers" => ["expires"]
        ];

        $msg = self::packFrame(pack("N", 100), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG);
        $msg .= self::packHeader($headers, true, 1, 1);
        $msg .= self::packFrame("a", Http2Driver::DATA, Http2Driver::NOFLAG, 1);
        $msg .= self::packFrame("", Http2Driver::DATA, Http2Driver::NOFLAG, 1);
        $msg .= self::packFrame("b", Http2Driver::DATA, Http2Driver::NOFLAG, 1);
        $msg .= self::packHeader(["expires" => ["date"]], false, 1);

        $expectations = [
            "protocol"    => "2.0",
            "method"      => "GET",
            "uri"         => "/foo",
            "host"        => "localhost",
            "headers"     => ["trailers" => ["expires"]],
            "body"        => "ab",
            "trailers"    => ["expires" => ["date"]],
        ];

        $return[] = [$msg, $expectations];

        return $return;
    }

    public function setupDriver(callable $onMessage = null, callable $writer = null, Options $options = null): array {
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

        $parser = $driver->setup(
            $this->createMock(Client::class),
            $onMessage ?? function () {},
            $writer ?? $this->createCallback(0)
        );

        return [$driver, $parser];
    }

    public function testWriterAbortAfterHeaders() {
        $buffer = "";
        $options = new Options;
        $driver = new Http2Driver($options, $this->createMock(TimeReference::class));
        $parser = $driver->setup(
            $this->createMock(Client::class),
            $this->createCallback(0),
            function (string $data, bool $close = false) use (&$buffer) {
                // HTTP/2 shall only reset streams, not abort the connection
                $this->assertFalse($close);
                $buffer .= $data;
                return new Success;
            },
            "" // Simulate upgrade request.
        );

        $parser->send("PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n");

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/"), [], null, "2.0");

        $emitter = new Emitter;
        $coroutine = $driver->writer(new Response(new IteratorStream($emitter->iterate())), $request);

        $emitter->emit("foo");
        $emitter->fail(new \Exception);

        $data = self::packFrame(pack(
            "nNnNnNnN",
            Http2Driver::INITIAL_WINDOW_SIZE,
            $options->getMaxBodySize(),
            Http2Driver::MAX_CONCURRENT_STREAMS,
            $options->getMaxConcurrentStreams(),
            Http2Driver::MAX_HEADER_LIST_SIZE,
            $options->getMaxHeaderSize(),
            Http2Driver::MAX_FRAME_SIZE,
            Http2Driver::DEFAULT_MAX_FRAME_SIZE
        ), Http2Driver::SETTINGS, Http2Driver::NOFLAG, 0);

        $hpack = new HPack;
        $data .= self::packFrame($hpack->encode([
            ":status" => 200,
            "date" => [""],
        ]), Http2Driver::HEADERS, Http2Driver::END_HEADERS, 1);

        $data .= self::packFrame("foo", Http2Driver::DATA, Http2Driver::NOFLAG, 1);
        $data .= self::packFrame(pack("N", Http2Driver::INTERNAL_ERROR), Http2Driver::RST_STREAM, Http2Driver::NOFLAG, 1);

        $this->assertEquals($data, $buffer);
    }

    public function testPingPong() {
        list($driver, $parser) = $this->setupDriver();

        $parser->send("PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n");
        $driver->frames = []; // ignore settings and window updates...

        $parser->send(self::packFrame("blahbleh", Http2Driver::PING, Http2Driver::NOFLAG));

        $this->assertEquals([["blahbleh", Http2Driver::PING, Http2Driver::ACK, 0]], $driver->frames);
    }

    public function testFlowControl() {
        list($driver, $parser) = $this->setupDriver(function (Request $read) use (&$request) {
            $request = $read;
        }, null, (new Options)->withOutputBufferSize(1));

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

        $emitter = new Emitter;
        $writer = $driver->writer(new Response(
            new IteratorStream($emitter->iterate()),
            ["content-type" => "text/html; charset=utf-8"]
        ), $request);

        $hpack = new HPack;
        $this->assertEquals([$hpack->encode([
            ":status" => 200,
            "content-type" => ["text/html; charset=utf-8"],
            "date" => [""],
        ]), Http2Driver::HEADERS, Http2Driver::END_HEADERS, 1], array_pop($driver->frames));

        $emitter->emit(str_repeat("_", 66002));
        $emitter->complete();

        $recv = "";
        foreach ($driver->frames as list($data, $type, $flags, $stream)) {
            $recv .= $data;
            $this->assertEquals(Http2Driver::DATA, $type);
            $this->assertEquals(Http2Driver::NOFLAG, $flags);
            $this->assertEquals(1, $stream);
        }
        $driver->frames = [];

        $this->assertEquals(Http2Driver::DEFAULT_WINDOW_SIZE, \strlen($recv)); // global window!!

        $chunkSize = 66000 - HTTP2Driver::DEFAULT_WINDOW_SIZE;
        $parser->send(self::packFrame(pack("N", $chunkSize), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG));

        $this->assertCount(1, $driver->frames);
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::NOFLAG, $flags);
        $this->assertEquals($chunkSize, \strlen($data));
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

        $emitter = new Emitter;
        $writer = $driver->writer(new Response(
            new IteratorStream($emitter->iterate()),
            ["content-type" => "text/html; charset=utf-8"]
        ), $request);

        $hpack = new HPack;
        $this->assertEquals([$hpack->encode([
            ":status" => 200,
            "content-type" => ["text/html; charset=utf-8"],
            "date" => [""],
        ]), Http2Driver::HEADERS, Http2Driver::END_HEADERS, 3], array_pop($driver->frames));

        $emitter->emit("**");

        $this->assertCount(1, $driver->frames);
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::NOFLAG, $flags);
        $this->assertEquals("**", $data);
        $this->assertEquals(3, $stream);

        $emitter->emit("*");
        $this->assertCount(0, $driver->frames); // global window too small

        $parser->send(self::packFrame(pack("N", 1), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG));

        $this->assertCount(1, $driver->frames);
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::NOFLAG, $flags);
        $this->assertEquals("*", $data);
        $this->assertEquals(3, $stream);

        $emitter->complete();

        $this->assertCount(1, $driver->frames);
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::END_STREAM, $flags);
        $this->assertEquals("", $data);
        $this->assertEquals(3, $stream);
    }

    public function testClosingStreamYieldsFalseFromWriter() {
        $driver = new Http2Driver(new Options, $this->createMock(TimeReference::class));

        $parser = $driver->setup(
            $this->createMock(Client::class),
            function (Request $read) use (&$request) {
                $request = $read;
                return new Success;
            },
            function () {
                return new Success;
            }
        );

        $parser->send("PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n");

        $headers = [
            ":authority" => "localhost",
            ":path" => "/",
            ":scheme" => "http",
            ":method" => "GET",
        ];
        $parser->send(self::packHeader($headers, false, 1));

        // $onMessage callback should be invoked.
        $this->assertInstanceOf(Request::class, $request);

        $emitter = new Emitter;
        $writer = $driver->writer(new Response(new IteratorStream($emitter->iterate())), $request);

        $emitter->emit("{data}");

        $parser->send(self::packFrame(
            \pack("N", Http2Driver::REFUSED_STREAM),
            Http2Driver::RST_STREAM,
            Http2Driver::NOFLAG,
            1
        ));

        $emitter->emit("{data}");

        Promise\wait($writer); // Will throw if the writer is not complete.
    }
}
