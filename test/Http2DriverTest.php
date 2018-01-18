<?php

namespace Aerys\Test;

use Aerys\Internal\Client;
use Aerys\Internal\HPack;
use Aerys\Internal\Http2Driver;
use Aerys\Options;
use Aerys\NullBody;
use Aerys\Request;
use Aerys\Response;
use Aerys\Server;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use Amp\Uri\Uri;

class Http2DriverTest extends TestCase {
    public function packFrame($data, $type, $flags, $stream = 0) {
        return substr(pack("N", \strlen($data)), 1, 3) . $type . $flags . pack("N", $stream) . $data;
    }

    public function packHeader($headers, $hasBody = false, $stream = 1, $split = PHP_INT_MAX) {
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
            $data .= $this->packFrame($frame, $type, $flag, $stream);
            $type = Http2Driver::CONTINUATION;
            $flag = Http2Driver::NOFLAG;
        }
        return $data . $this->packFrame($end, $type, ($hasBody ? $flag : Http2Driver::END_STREAM | $flag) | Http2Driver::END_HEADERS, $stream);
    }

    /**
     * @dataProvider provideSimpleCases
     */
    public function testSimpleCases($msg, $expectations) {
        $msg = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n$msg";

        $driver = new class($this) extends Http2Driver {
            /** @var TestCase */
            private $test;

            public function __construct($test) {
                parent::__construct();
                $this->test = $test;
            }

            protected function writeFrame(Client $client, string $data, string $type, string $flags, int $stream = 0) {
                if ($type === Http2Driver::RST_STREAM || $type === Http2Driver::GOAWAY) {
                    $this->test->fail("RST_STREAM or GOAWAY frame received");
                }
            }
        };

        $resultEmitter = function ($client, Request $req) use (&$request) {
            $request = $req;
        };

        $driver->setup($this->createMock(Server::class), $resultEmitter, $this->createCallback(0), $this->createCallback(0));

        for ($mode = 0; $mode <= 1; $mode++) {
            $parseResult = null;

            $client = new Client;
            $client->options = new Options;
            $client->serverAddr = "127.0.0.1";
            $port = $client->serverPort = 80;

            $parser = $driver->parser($client);

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
            $this->assertSame($expectations["port"] ?? $port, $request->getUri()->getPort(), "uriPort mismatch");
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
        $msg = $this->packFrame(pack("N", 100), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG);
        $msg .= $this->packHeader($headers);

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

        $msg = $this->packFrame(pack("N", 100), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG);
        $msg .= $this->packHeader($headers, true, 1, 1);
        $msg .= $this->packFrame("a", Http2Driver::DATA, Http2Driver::NOFLAG, 1);
        $msg .= $this->packFrame("", Http2Driver::DATA, Http2Driver::NOFLAG, 1);
        $msg .= $this->packFrame("b", Http2Driver::DATA, Http2Driver::END_STREAM, 1);

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

    public function setupDriver() {
        $driver = new class($this) extends Http2Driver {
            public $frames = [];

            /** @var TestCase */
            private $test;

            public function __construct($test) {
                parent::__construct();
                $this->test = $test;
            }

            protected function writeFrame(Client $client, string $data, string $type, string $flags, int $stream = 0) {
                if ($type === Http2Driver::RST_STREAM || $type === Http2Driver::GOAWAY) {
                    $this->test->fail("RST_STREAM or GOAWAY frame received");
                }

                if ($type === Http2Driver::WINDOW_UPDATE) {
                    return; // we don't test this as we always give a far too large window currently ;-)
                }

                $this->frames[] = [$data, $type, $flags, $stream];
            }
        };

        $driver->setup(
            $this->createMock(Server::class),
            function () {},
            $this->createCallback(0),
            $this->createCallback(0)
        );

        return $driver;
    }

    public function testWriterAbortAfterHeaders() {
        $driver = new Http2Driver;
        $buffer = "";
        $driver->setup(
            $this->createMock(Server::class),
            $this->createCallback(0),
            $this->createCallback(0),
            function (Client $client, string $data, bool $final) use (&$buffer) {
                // HTTP/2 shall only reset streams, not abort the connection
                $this->assertFalse($final);
                $this->assertFalse($client->shouldClose);
                $buffer .= $data;
            }
        );

        $streamId = 2;

        $client = new Client;
        $client->options = new Options;
        $client->streamWindow[$streamId] = 65536;
        $client->streamWindowBuffer[$streamId] = "";

        $request = new Request("GET", new Uri("/"), [], new NullBody, "/", "2.0", $streamId);

        $writer = $driver->writer($client, new Response, $request);

        $writer->send("foo");

        unset($writer);

        $data = $this->packFrame(HPack::encode([
            ":status" => 200,
            "date" => [null],
        ]), Http2Driver::HEADERS, Http2Driver::END_HEADERS, 2);
        $data .= $this->packFrame("foo", Http2Driver::DATA, Http2Driver::NOFLAG, 2);
        $data .= $this->packFrame(pack("N", Http2Driver::INTERNAL_ERROR), Http2Driver::RST_STREAM, Http2Driver::NOFLAG, 2);

        $this->assertEquals($data, $buffer);
    }

    public function testPingPong() {
        $driver = $this->setupDriver();

        $client = new Client;
        $client->options = new Options;
        $parser = $driver->parser($client);

        $parser->send("PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n");
        $driver->frames = []; // ignore settings and window updates...

        $parser->send($this->packFrame("blahbleh", Http2Driver::PING, Http2Driver::NOFLAG));

        $this->assertEquals([["blahbleh", Http2Driver::PING, Http2Driver::ACK, 0]], $driver->frames);
    }

    public function testFlowControl() {
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
        $parser->send($this->packHeader($headers));

        $streamId = 1;
        $request = new Request("GET", new Uri("/"), [], new NullBody, "/", "2.0", $streamId);

        $client->options = $client->options->withOutputBufferSize(1); // Force data frame when any data is written.
        $writer = $driver->writer($client, new Response(null, ["content-type" => "text/html; charset=utf-8"]), $request);
        $writer->valid(); // Start writer.

        $this->assertEquals([HPack::encode([
            ":status" => 200,
            "content-type" => ["text/html; charset=utf-8"],
            "date" => [null],
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

        $parser->send($this->packFrame(pack("N", 464 /* until 66000 */), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG));

        $this->assertCount(1, $driver->frames);
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::NOFLAG, $flags);
        $this->assertEquals(464, \strlen($data));
        $this->assertEquals(1, $stream);

        $parser->send($this->packFrame(pack("N", 4), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG));
        $this->assertCount(0, $driver->frames); // global window update alone must not trigger send

        $parser->send($this->packFrame(pack("N", 1), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG, 1));

        $this->assertCount(1, $driver->frames);
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::NOFLAG, $flags);
        $this->assertEquals("_", $data);
        $this->assertEquals(1, $stream);

        $parser->send($this->packFrame(pack("N", 1), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG, 1));

        $this->assertCount(1, $driver->frames);
        list($data, $type, $flags, $stream) = array_pop($driver->frames);
        $this->assertEquals(Http2Driver::DATA, $type);
        $this->assertEquals(Http2Driver::END_STREAM, $flags);
        $this->assertEquals("_", $data);
        $this->assertEquals(1, $stream);

        $parser->send($this->packHeader($headers, false, 3));

        $streamId = 3;
        $request = new Request("GET", new Uri("/"), [], new NullBody, "/", "2.0", $streamId);

        $writer = $driver->writer($client, new Response(null, ["content-type" => "text/html; charset=utf-8"]), $request);
        $writer->valid(); // Start writer.

        $this->assertEquals([HPack::encode([
            ":status" => 200,
            "content-type" => ["text/html; charset=utf-8"],
            "date" => [null],
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

        $parser->send($this->packFrame(pack("N", 1), Http2Driver::WINDOW_UPDATE, Http2Driver::NOFLAG));

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
