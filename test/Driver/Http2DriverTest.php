<?php /** @noinspection PhpPropertyOnlyWrittenInspection */

namespace Amp\Http\Server\Test\Driver;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableBuffer;
use Amp\ByteStream\WritableStream;
use Amp\Future;
use Amp\Http\HPack;
use Amp\Http\Http2\Http2Parser;
use Amp\Http\Internal\HPackNghttp2;
use Amp\Http\Server\Driver\Http2Driver;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Trailers;
use Amp\Http\Status;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Queue;
use League\Uri;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;
use function Amp\Http\formatDateHeader;
use function Amp\Http\Server\streamChunks;

class Http2DriverTest extends HttpDriverTest
{
    public static function packFrame(string $data, string $type, string $flags, int $stream = 0): string
    {
        return \substr(\pack("NccN", \strlen($data), $type, $flags, $stream), 1) . $data;
    }

    public static function packHeader(
        array $headers,
        bool $continue = false,
        int $stream = 1,
        int $split = \PHP_INT_MAX
    ): string {
        $input = [];

        foreach ($headers as $field => $values) {
            foreach ((array) $values as $value) {
                $input[] = [$field, $value];
            }
        }

        $data = "";
        $hpack = new HPack;
        $headers = $hpack->encode($input);
        $all = \str_split($headers, $split);
        if ($split !== PHP_INT_MAX) {
            $flag = Http2Parser::PADDED;
            $len = 1;
            $all[0] = \chr($len) . $all[0] . \str_repeat("\0", $len);
        } else {
            $flag = Http2Parser::NO_FLAG;
        }

        $end = \array_pop($all);
        $type = Http2Parser::HEADERS;

        foreach ($all as $frame) {
            $data .= self::packFrame($frame, $type, $flag, $stream);
            $type = Http2Parser::CONTINUATION;
            $flag = Http2Parser::NO_FLAG;
        }

        $flags = ($continue ? $flag : Http2Parser::END_STREAM | $flag) | Http2Parser::END_HEADERS;

        return $data . self::packFrame($end, $type, $flags, $stream);
    }

    private static function thenFrameIsEqualTo(
        array $frame,
        string $data,
        int $type,
        int $flags,
        int $stream
    ): void {
        $frame[0] = \bin2hex($frame[0]);

        self::assertSame([\bin2hex($data), $type, $flags, $stream], $frame);
    }

    private Http2Driver $driver;

    private ReadableStream $input;

    private WritableStream $output;

    private ?EventLoop\Suspension $requestSuspension = null;

    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new Http2Driver(new ClosureRequestHandler(function ($req) {
            $this->requests[] = $req;

            if ($this->requestSuspension) {
                $this->requestSuspension->resume($req);
                $this->requestSuspension = null;
            }

            return new Response;
        }), $this->createMock(ErrorHandler::class), new NullLogger, new Options);

        $this->input = new ReadableBuffer('');
        $this->output = new WritableBuffer();
    }

    protected function givenInput(ReadableStream $input): void
    {
        $this->input = $input;
    }

    protected function whenRequestIsReceived(): Request
    {
        async(fn () => $this->driver->handleClient($this->createClientMock(), $this->input, $this->output));

        $this->requestSuspension = EventLoop::getSuspension();

        return $this->requestSuspension->suspend();
    }

    /**
     * @dataProvider provideSimpleCases
     */
    public function testSimpleCases(ReadableStream $input, array $expectations): void
    {
        $this->givenInput($input);

        $request = $this->whenRequestIsReceived();

        $body = $request->getBody()->buffer();
        $trailers = $request->getTrailers()?->await();

        $headers = $request->getHeaders();
        foreach ($headers as $header => $value) {
            if ($header[0] === ":") {
                unset($headers[$header]);
            }
        }

        $defaultPort = $request->getUri()->getScheme() === "https" ? 443 : 80;
        self::assertSame($expectations["protocol"], $request->getProtocolVersion());
        self::assertSame($expectations["method"], $request->getMethod());
        self::assertSame($expectations["uri"], $request->getUri()->getPath());
        self::assertSame($expectations["headers"], $headers);
        self::assertSame($expectations["port"] ?? 80, $request->getUri()->getPort() ?: $defaultPort);
        self::assertSame($expectations["host"], $request->getUri()->getHost());
        self::assertSame($expectations["body"], $body);
        self::assertSame($expectations["trailers"] ?? [], $trailers ? $trailers->getHeaders() : []);
    }

    public function provideSimpleCases(): iterable
    {
        // 0 --- basic request -------------------------------------------------------------------->
        $headers = [
            ":authority" => ["localhost:8888"],
            ":path" => ["/foo"],
            ":scheme" => ["http"],
            ":method" => ["GET"],
            "test" => ["successful"],
        ];

        $input = Http2Parser::PREFACE;
        $input .= self::packFrame(\pack("N", 100), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG);
        $input .= self::packHeader($headers);

        $expectations = [
            "protocol" => "2",
            "method" => "GET",
            "uri" => "/foo",
            "host" => "localhost",
            "port" => 8888,
            "headers" => ["test" => ["successful"]],
            "body" => "",
        ];

        yield "basic, buffered" => [new ReadableBuffer($input), $expectations];
        yield "basic, streamed" => [streamChunks($input), $expectations];

        // 1 --- request with partial (continuation) frames --------------------------------------->
        $headers[":authority"] = "localhost";

        $input = Http2Parser::PREFACE;
        $input .= self::packFrame(\pack("N", 100), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG);
        $input .= self::packHeader($headers, true, 1, 1);
        $input .= self::packFrame("a", Http2Parser::DATA, Http2Parser::NO_FLAG, 1);
        $input .= self::packFrame("", Http2Parser::DATA, Http2Parser::NO_FLAG, 1);
        $input .= self::packFrame("b", Http2Parser::DATA, Http2Parser::END_STREAM, 1);

        $expectations = [
            "protocol" => "2",
            "method" => "GET",
            "uri" => "/foo",
            "host" => "localhost",
            "headers" => ["test" => ["successful"]],
            "body" => "ab",
        ];

        yield "partial / continuation, buffered" => [new ReadableBuffer($input), $expectations];
        yield "partial / continuation, streamed" => [streamChunks($input), $expectations];

        // 2 --- request trailing headers --------------------------------------------------------->
        $headers = [
            ":authority" => ["localhost"],
            ":path" => ["/foo"],
            ":scheme" => ["http"],
            ":method" => ["GET"],
            "te" => ["trailers"],
            "trailer" => ["expires"],
        ];

        $input = Http2Parser::PREFACE;
        $input .= self::packFrame(\pack("N", 100), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG);
        $input .= self::packHeader($headers, true, 1, 1);
        $input .= self::packFrame("a", Http2Parser::DATA, Http2Parser::NO_FLAG, 1);
        $input .= self::packFrame("", Http2Parser::DATA, Http2Parser::NO_FLAG, 1);
        $input .= self::packFrame("b", Http2Parser::DATA, Http2Parser::NO_FLAG, 1);
        $input .= self::packHeader(["expires" => ["date"]], false, 1);

        $expectations = [
            "protocol" => "2",
            "method" => "GET",
            "uri" => "/foo",
            "host" => "localhost",
            "headers" => ["te" => ["trailers"], "trailer" => ["expires"]],
            "body" => "ab",
            "trailers" => ["expires" => ["date"]],
        ];

        yield "trailers, buffered" => [new ReadableBuffer($input), $expectations];
        yield "trailers, streamed" => [streamChunks($input), $expectations];
    }

    public function testWrite(): void
    {
        $parser->send(Http2Parser::PREFACE);

        $request = new Request($this->createClientMock(), "GET", Uri\Http::createFromString('/'), [], '', '2');

        $body = "foo";
        $trailers = new Trailers(Future::complete(["expires" => "date"]), ["expires"]);

        $queue = new Queue();
        EventLoop::queue(fn () => $driver->write($request, new Response(Status::OK, [
            "content-length" => \strlen($body),
        ], new ReadableIterableStream($queue->pipe()), $trailers)));

        $queue->pushAsync($body);
        $queue->complete();

        $data = self::packFrame(\pack(
            "nNnNnNnN",
            Http2Parser::INITIAL_WINDOW_SIZE,
            $options->getBodySizeLimit(),
            Http2Parser::MAX_CONCURRENT_STREAMS,
            $options->getConcurrentStreamLimit(),
            Http2Parser::MAX_HEADER_LIST_SIZE,
            $options->getHeaderSizeLimit(),
            Http2Parser::MAX_FRAME_SIZE,
            Http2Driver::DEFAULT_MAX_FRAME_SIZE
        ), Http2Parser::SETTINGS, Http2Parser::NO_FLAG, 0);

        $hpack = new HPack;

        $data .= self::packFrame('', Http2Parser::SETTINGS, Http2Parser::ACK);

        $data .= self::packFrame($hpack->encode([
            [":status", (string) Status::OK],
            ["content-length", \strlen($body)],
            ["trailer", "expires"],
            ["date", formatDateHeader()],
        ]), Http2Parser::HEADERS, Http2Parser::END_HEADERS, 1);

        $data .= self::packFrame("foo", Http2Parser::DATA, Http2Parser::NO_FLAG, 1);

        $data .= self::packFrame($hpack->encode([
            ["expires", "date"],
        ]), Http2Parser::HEADERS, Http2Parser::END_HEADERS | Http2Parser::END_STREAM, 1);

        delay(0.1);

        self::assertEquals($data, $buffer);
    }

    public function testWriterAbortAfterHeaders(): void
    {
        $buffer = "";
        $options = new Options;
        $driver = new Http2Driver($options, new NullLogger);
        $parser = $driver->setup(
            $this->createClientMock(),
            $this->createCallback(0),
            function (string $data, bool $close = false) use (&$buffer) {
                // HTTP/2 shall only reset streams, not abort the connection
                $this->assertFalse($close);
                $buffer .= $data;
                return Future::complete();
            },
            "" // Simulate upgrade request.
        );

        $parser->send(Http2Parser::PREFACE);

        $request = new Request($this->createClientMock(), "GET", Uri\Http::createFromString('/'), [], '', '2');

        $queue = new Queue();
        EventLoop::queue(fn () => $driver->write(
            $request,
            new Response(Status::OK, [], new ReadableIterableStream($queue->pipe()))
        ));

        $queue->pushAsync("foo");
        $queue->error(new \Exception);

        $data = self::packFrame(\pack(
            "nNnNnNnN",
            Http2Parser::INITIAL_WINDOW_SIZE,
            $options->getBodySizeLimit(),
            Http2Parser::MAX_CONCURRENT_STREAMS,
            $options->getConcurrentStreamLimit(),
            Http2Parser::MAX_HEADER_LIST_SIZE,
            $options->getHeaderSizeLimit(),
            Http2Parser::MAX_FRAME_SIZE,
            Http2Driver::DEFAULT_MAX_FRAME_SIZE
        ), Http2Parser::SETTINGS, Http2Parser::NO_FLAG, 0);

        $hpack = new HPack;

        $data .= self::packFrame('', Http2Parser::SETTINGS, Http2Parser::ACK);

        $data .= self::packFrame($hpack->encode([
            [":status", (string) Status::OK],
            ["date", formatDateHeader()],
        ]), Http2Parser::HEADERS, Http2Parser::END_HEADERS, 1);

        $data .= self::packFrame("foo", Http2Parser::DATA, Http2Parser::NO_FLAG, 1);
        $data .= self::packFrame(
            \pack("N", Http2Parser::INTERNAL_ERROR),
            Http2Parser::RST_STREAM,
            Http2Parser::NO_FLAG,
            1
        );

        delay(0.1);

        self::assertEquals($data, $buffer);
    }

    public function testPingPong(): void
    {
        $parser->send(Http2Parser::PREFACE);


        $parser->send(self::packFrame("blahbleh", Http2Parser::PING, Http2Parser::NO_FLAG));

        // ignore settings and window updates...
        self::assertEquals([["blahbleh", Http2Parser::PING, Http2Parser::ACK, 0]], $driver->frames);
    }

    public function testFlowControl(): void
    {
        /** @noinspection PhpInternalEntityUsedInspection */
        if (HPackNghttp2::isSupported()) {
            self::markTestSkipped('Not supported with nghttp2, disable ffi for this test.');
        }

        [$driver, $parser] = $this->setupDriver(function (Request $read) use (&$request) {
            $request = $read;
        }, (new Options)->withStreamThreshold(1)); // Set stream threshold to 1 to force immediate writes to client.

        $parser->send(Http2Parser::PREFACE);

        foreach ($driver->frames as [$data, $type, $flags, $stream]) {
            self::assertEquals(Http2Parser::SETTINGS, $type);
            self::assertEquals(0, $stream);
        }
        $driver->frames = [];

        $parser->send(self::packFrame(
            \pack("nN", Http2Parser::INITIAL_WINDOW_SIZE, 66000),
            Http2Parser::SETTINGS,
            Http2Parser::NO_FLAG
        ));
        self::assertCount(1, $driver->frames);
        [$data, $type, $flags, $stream] = \array_pop($driver->frames);
        self::assertEquals(Http2Parser::SETTINGS, $type);
        self::assertEquals(Http2Parser::ACK, $flags);
        self::assertEquals("", $data);
        self::assertEquals(0, $stream);

        $headers = [
            ":authority" => "localhost",
            ":path" => "/",
            ":scheme" => "http",
            ":method" => "GET",
            "test" => "successful",
        ];
        $parser->send(self::packHeader($headers));

        // $onMessage callback should be invoked.
        self::assertInstanceOf(Request::class, $request);

        $queue = new Queue();
        EventLoop::queue(fn () => $driver->write($request, new Response(
            Status::OK,
            ["content-type" => "text/html; charset=utf-8"],
            new ReadableIterableStream($queue->pipe())
        )));

        delay(0);

        $hpack = new HPack;
        self::assertEquals([
            $hpack->encode([
                [":status", (string) Status::OK],
                ["content-type", "text/html; charset=utf-8"],
                ["date", formatDateHeader()],
            ]),
            Http2Parser::HEADERS,
            Http2Parser::END_HEADERS,
            1,
        ], \array_pop($driver->frames));

        $queue->pushAsync(\str_repeat("_", 66002))->ignore();
        $queue->complete();

        delay(0.1);

        $recv = "";
        foreach ($driver->frames as [$data, $type, $flags, $stream]) {
            $recv .= $data;
            self::assertEquals(Http2Parser::DATA, $type);
            self::assertEquals(Http2Parser::NO_FLAG, $flags);
            self::assertEquals(1, $stream);
        }
        $driver->frames = [];

        self::assertEquals(Http2Driver::DEFAULT_WINDOW_SIZE, \strlen($recv)); // global window!!

        $chunkSize = 66000 - Http2Driver::DEFAULT_WINDOW_SIZE;
        $parser->send(self::packFrame(\pack("N", $chunkSize), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG));

        delay(0.1); // Allow loop to tick for defer to execute in driver.

        self::assertCount(1, $driver->frames);
        [$data, $type, $flags, $stream] = \array_pop($driver->frames);
        self::assertEquals(Http2Parser::DATA, $type);
        self::assertEquals(Http2Parser::NO_FLAG, $flags);
        self::assertEquals($chunkSize, \strlen($data));
        self::assertEquals(1, $stream);

        $parser->send(self::packFrame(\pack("N", 4), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG));
        self::assertCount(0, $driver->frames); // global window update alone must not trigger send

        $parser->send(self::packFrame(\pack("N", 1), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG, 1));

        delay(0.1); // Allow loop to tick for defer to execute in driver.

        self::assertCount(1, $driver->frames);
        [$data, $type, $flags, $stream] = \array_pop($driver->frames);
        self::assertEquals(Http2Parser::DATA, $type);
        self::assertEquals(Http2Parser::NO_FLAG, $flags);
        self::assertEquals("_", $data);
        self::assertEquals(1, $stream);

        $parser->send(self::packFrame(\pack("N", 1), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG, 1));

        delay(0.1); // Allow loop to tick for defer to execute in driver.

        self::assertCount(2, $driver->frames);
        [$data, $type, $flags, $stream] = \array_shift($driver->frames);
        self::assertEquals(Http2Parser::DATA, $type);
        self::assertEquals(Http2Parser::NO_FLAG, $flags);
        self::assertEquals("_", $data);
        self::assertEquals(1, $stream);

        [$data, $type, $flags, $stream] = \array_shift($driver->frames);
        self::assertEquals(Http2Parser::DATA, $type);
        self::assertEquals(Http2Parser::END_STREAM, $flags);
        self::assertEquals("", $data);
        self::assertEquals(1, $stream);

        $parser->send(self::packHeader($headers, false, 3));

        // $onMessage callback should be invoked.
        self::assertInstanceOf(Request::class, $request);

        $queue = new Queue();
        EventLoop::queue(fn () => $driver->write($request, new Response(
            Status::OK,
            ["content-type" => "text/html; charset=utf-8"],
            new ReadableIterableStream($queue->pipe())
        )));

        delay(0.001);

        $hpack = new HPack;

        self::thenFrameIsEqualTo(
            \array_pop($driver->frames),
            $hpack->encode([
                [":status", (string) Status::OK],
                ["content-type", "text/html; charset=utf-8"],
                ["date", formatDateHeader()],
            ]),
            Http2Parser::HEADERS,
            Http2Parser::END_HEADERS,
            3
        );

        $queue->pushAsync("**")->ignore();

        delay(0.1); // Allow loop to tick for defer to execute in driver.

        self::assertCount(1, $driver->frames);
        [$data, $type, $flags, $stream] = \array_pop($driver->frames);
        self::assertEquals(Http2Parser::DATA, $type);
        self::assertEquals(Http2Parser::NO_FLAG, $flags);
        self::assertEquals("**", $data);
        self::assertEquals(3, $stream);

        $queue->pushAsync("*")->ignore();
        self::assertCount(0, $driver->frames); // global window too small

        $parser->send(self::packFrame(\pack("N", 1), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG));

        delay(0.1); // Allow loop to tick for defer to execute in driver.

        self::assertCount(1, $driver->frames);
        [$data, $type, $flags, $stream] = \array_pop($driver->frames);
        self::assertEquals(Http2Parser::DATA, $type);
        self::assertEquals(Http2Parser::NO_FLAG, $flags);
        self::assertEquals("*", $data);
        self::assertEquals(3, $stream);

        $queue->complete();

        delay(0.1); // Allow loop to tick for defer to execute in driver.

        self::assertCount(1, $driver->frames);
        [$data, $type, $flags, $stream] = \array_pop($driver->frames);
        self::assertEquals(Http2Parser::DATA, $type);
        self::assertEquals(Http2Parser::END_STREAM, $flags);
        self::assertEquals("", $data);
        self::assertEquals(3, $stream);
    }

    public function testClosingStreamYieldsFalseFromWriter(): void
    {
        $driver = new Http2Driver(new Options, new NullLogger);

        $parser = $driver->setup(
            $this->createClientMock(),
            function (Request $read) use (&$request): Future {
                $request = $read;
                return Future::complete();
            },
            fn () => Future::complete(),
        );

        $parser->send(Http2Parser::PREFACE);

        $headers = [
            ":authority" => "localhost",
            ":path" => "/",
            ":scheme" => "http",
            ":method" => "GET",
        ];
        $parser->send(self::packHeader($headers));

        // $onMessage callback should be invoked.
        self::assertInstanceOf(Request::class, $request);

        $queue = new Queue();
        $writer = async(fn () => $driver->write(
            $request,
            new Response(Status::OK, [], new ReadableIterableStream($queue->pipe()))
        ));

        $queue->pushAsync("{data}")->ignore();

        $parser->send(self::packFrame(
            \pack("N", Http2Parser::REFUSED_STREAM),
            Http2Parser::RST_STREAM,
            Http2Parser::NO_FLAG,
            1
        ));

        $queue->pushAsync("{data}")->ignore();

        $writer->await(); // Will throw if the writer is not complete.
    }

    public function testPush(): void
    {
        /** @noinspection PhpInternalEntityUsedInspection */
        if (HPackNghttp2::isSupported()) {
            self::markTestSkipped('Not supported with nghttp2, disable ffi for this test.');
        }

        $driver = new Http2Driver(new Options, new NullLogger);

        $requests = [];

        $parser = $driver->setup(
            $this->createClientMock(),
            function (Request $read) use (&$requests) {
                $requests[] = $read;
                return Future::complete();
            },
            fn () => Future::complete(),
        );

        $parser->send(Http2Parser::PREFACE);

        $headers = [
            ":authority" => "localhost",
            ":path" => "/base",
            ":scheme" => "http",
            ":method" => "GET",
        ];

        $parser->send(self::packHeader($headers));

        $response = new Response(Status::CREATED);
        $response->push("/absolute/path");
        $response->push("relative/path");
        $response->push("path/with/query?key=value");

        self::assertInstanceOf(Request::class, $requests[0]);

        $driver->write($requests[0], $response);

        $paths = ["/base", "/absolute/path", "/base/relative/path", "/base/path/with/query"];

        self::assertCount(\count($paths), $requests);

        foreach ($requests as $id => $requested) {
            self::assertSame($paths[$id], $requested->getUri()->getPath());
        }

        $request = $requests[\count($requests) - 1];
        self::assertSame("key=value", $request->getUri()->getQuery());
    }

    public function testPingFlood(): void
    {
        $driver = new Http2Driver(new Options, new NullLogger);

        $client = $this->createClientMock();
        $client->expects(self::atLeastOnce())
            ->method('close');

        $lastWrite = null;

        $parser = $driver->setup(
            $client,
            $this->createCallback(0),
            function (string $data) use (&$lastWrite): Future {
                $lastWrite = $data;
                return Future::complete();
            }
        );

        $parser->send(Http2Parser::PREFACE);

        $buffer = "";
        $ping = "aaaaaaaa";
        for ($i = 0; $i < 1024; ++$i) {
            $buffer .= self::packFrame($ping++, Http2Parser::PING, Http2Parser::NO_FLAG);
        }

        $parser->send($buffer);

        self::assertSame(
            \bin2hex(self::packFrame(\pack("NN", 0, Http2Parser::ENHANCE_YOUR_CALM), Http2Parser::GOAWAY,
                Http2Parser::NO_FLAG)),
            \bin2hex($lastWrite)
        );
    }

    public function testSendingResponseBeforeRequestCompletes(): void
    {
        $driver = new Http2Driver(new Options, new NullLogger);
        $invoked = false;
        $parser = $driver->setup(
            $this->createClientMock(),
            function () use (&$invoked) {
                $invoked = true;
                return Future::complete();
            },
            function (string $data): Future {
                $type = \ord($data[3]);

                if ($type === Http2Parser::RST_STREAM || $type === Http2Parser::GOAWAY) {
                    AsyncTestCase::fail("RST_STREAM or GOAWAY frame received");
                }

                return Future::complete();
            }
        );

        $parser->send(Http2Parser::PREFACE);

        $headers = [
            ":authority" => "localhost",
            ":path" => "/",
            ":scheme" => "http",
            ":method" => "GET",
        ];
        $parser->send(self::packHeader($headers, true, 1)); // Stream 1 used for upgrade request.

        self::assertTrue($invoked);

        // Note that Request object is not actually used in this test.
        $request = new Request($this->createClientMock(), "GET", Uri\Http::createFromString('/'), [], '', '2');
        $driver->write($request, new Response(Status::OK, [
            "content-length" => "0",
        ]));

        delay(0.1);

        $parser->send(self::packFrame("body-data", Http2Parser::DATA, Http2Parser::END_STREAM, 1));
    }
}
