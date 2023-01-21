<?php declare(strict_types=1);
/** @noinspection PhpPropertyOnlyWrittenInspection */

namespace Amp\Http\Server\Test\Driver;

use Amp\ByteStream\BufferedReader;
use Amp\ByteStream\Pipe;
use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamChain;
use Amp\CancelledException;
use Amp\Future;
use Amp\Http\HPack;
use Amp\Http\Http2\Http2Parser;
use Amp\Http\Internal\HPackNghttp2;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Driver\Http2Driver;
use Amp\Http\Server\Driver\HttpDriver;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Trailers;
use Amp\Http\Status;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Pipeline;
use Amp\Pipeline\Queue;
use Amp\TimeoutCancellation;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\delay;
use function Amp\Http\formatDateHeader;
use function Amp\Http\Server\streamChunks;

class Http2DriverTest extends HttpDriverTest
{
    public static function packFrame(string $data, int $type, int $flags, int $stream = 0): string
    {
        return Http2Parser::compileFrame($data, $type, $flags, $stream);
    }

    public static function packHeader(
        array $headers,
        bool  $continue = false,
        int   $stream = 1,
        int   $split = \PHP_INT_MAX
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

    private Http2Driver $driver;
    private ReadableStream $input;
    private Pipe $output;
    private BufferedReader $outputReader;
    private ?EventLoop\Suspension $requestSuspension = null;
    private \SplQueue $responses;
    private array $requests = [];
    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->initDriver();
        $this->input = new ReadableBuffer('');

        $this->output = new Pipe(\PHP_INT_MAX);
        $this->outputReader = new BufferedReader($this->output->getSource());
        $this->responses = new \SplQueue;
    }

    private function initDriver(array $options = []): void
    {
        $this->driver = new Http2Driver(
            new ClosureRequestHandler(function (Request $req): Response {
                $this->requests[] = $req;

                // Ensure microtasks are running between individual requests
                delay(0);

                if ($this->requestSuspension) {
                    $this->requestSuspension->resume($req);
                    $this->requestSuspension = null;
                }

                if ($this->responses->isEmpty()) {
                    $response = new Response;
                } else {
                    $response = $this->responses->pop();
                }

                foreach ($this->pushes as $push) {
                    $response->push($push);
                }
                $this->pushes = [];

                return $response;
            }),
            $this->createMock(ErrorHandler::class),
            new NullLogger,
            ...$options,
        );
    }

    protected function givenInput(ReadableStream $input): void
    {
        $this->input = $input;
    }

    protected function whenRequestIsReceived(): Request
    {
        async(fn () => $this->driver->handleClient(
            $this->createClientMock(),
            $this->input,
            $this->output->getSink(),
        ))->ignore();

        $this->requestSuspension = EventLoop::getSuspension();

        return $this->requestSuspension->suspend();
    }

    protected function whenClientIsHandled(?Client $client = null): void
    {
        $this->driver->handleClient(
            $client ?? $this->createClientMock(),
            $this->input,
            $this->output->getSink(),
        );
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

        $this->givenInput(new ReadableBuffer($input));

        $body = "foo";
        $trailers = new Trailers(Future::complete(["expires" => "date"]), ["expires"]);

        $queue = new Queue();
        $response = new Response(
            Status::OK,
            ["content-length" => \strlen($body)],
            new ReadableIterableStream($queue->pipe()),
            $trailers,
        );

        EventLoop::delay(0.05, static function () use ($queue, $body) {
            $queue->pushAsync($body);
            $queue->complete();
        });

        $request = async(fn () => $this->whenRequestIsReceived());
        $this->givenNextResponse($response);

        $frames = $this->whenReceivingFrames();

        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => 24,
            'type' => Http2Parser::SETTINGS,
            'flags' => Http2Parser::NO_FLAG,
            'stream' => 0,
            'buffer' => \pack(
                "nNnNnNnN",
                Http2Parser::INITIAL_WINDOW_SIZE,
                HttpDriver::DEFAULT_BODY_SIZE_LIMIT,
                Http2Parser::MAX_CONCURRENT_STREAMS,
                Http2Driver::DEFAULT_CONCURRENT_STREAM_LIMIT,
                Http2Parser::MAX_HEADER_LIST_SIZE,
                HttpDriver::DEFAULT_HEADER_SIZE_LIMIT,
                Http2Parser::MAX_FRAME_SIZE,
                Http2Driver::DEFAULT_MAX_FRAME_SIZE
            ),
        ], $frames->getValue());

        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => 8,
            'type' => Http2Parser::GOAWAY,
            'flags' => Http2Parser::NO_FLAG,
            'stream' => 0,
            'buffer' => "\x00\x00\x00\x01\x00\x00\x00\x00",
        ], $frames->getValue());

        $hpack = new HPack;

        self::assertTrue($frames->continue());
        $hpackBuffer = $hpack->encode([
            [":status", (string) Status::OK],
            ["content-length", \strlen($body)],
            ["trailer", "expires"],
            ["date", formatDateHeader()],
        ]);
        self::assertSame([
            'length' => \strlen($hpackBuffer),
            'type' => Http2Parser::HEADERS,
            'flags' => Http2Parser::END_HEADERS,
            'stream' => 1,
            'buffer' => $hpackBuffer,
        ], $frames->getValue());

        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => 3,
            'type' => Http2Parser::DATA,
            'flags' => Http2Parser::NO_FLAG,
            'stream' => 1,
            'buffer' => 'foo',
        ], $frames->getValue());

        self::assertTrue($frames->continue());
        $hpackBuffer = $hpack->encode([
            ["expires", "date"],
        ]);
        self::assertSame([
            'length' => \strlen($hpackBuffer),
            'type' => Http2Parser::HEADERS,
            'flags' => Http2Parser::END_HEADERS | Http2Parser::END_STREAM,
            'stream' => 1,
            'buffer' => $hpackBuffer,
        ], $frames->getValue());

        $request->await();
    }

    public function testWriterAbortAfterHeaders(): void
    {
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

        $this->givenInput(new ReadableBuffer($input));

        $body = "foo";
        $trailers = new Trailers(Future::complete(["expires" => "date"]), ["expires"]);

        $queue = new Queue();
        $response = new Response(
            Status::OK,
            ["content-length" => \strlen($body)],
            new ReadableIterableStream($queue->pipe()),
            $trailers,
        );

        EventLoop::delay(0.05, static function () use ($queue, $body) {
            $queue->pushAsync($body);
            $queue->error(new \Exception());
        });

        $request = async(fn () => $this->whenRequestIsReceived());
        $this->givenNextResponse($response);

        $frames = $this->whenReceivingFrames();

        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => 24,
            'type' => Http2Parser::SETTINGS,
            'flags' => Http2Parser::NO_FLAG,
            'stream' => 0,
            'buffer' => \pack(
                "nNnNnNnN",
                Http2Parser::INITIAL_WINDOW_SIZE,
                HttpDriver::DEFAULT_BODY_SIZE_LIMIT,
                Http2Parser::MAX_CONCURRENT_STREAMS,
                Http2Driver::DEFAULT_CONCURRENT_STREAM_LIMIT,
                Http2Parser::MAX_HEADER_LIST_SIZE,
                HttpDriver::DEFAULT_HEADER_SIZE_LIMIT,
                Http2Parser::MAX_FRAME_SIZE,
                Http2Driver::DEFAULT_MAX_FRAME_SIZE
            ),
        ], $frames->getValue());

        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => 8,
            'type' => Http2Parser::GOAWAY,
            'flags' => Http2Parser::NO_FLAG,
            'stream' => 0,
            'buffer' => "\x00\x00\x00\x01\x00\x00\x00\x00",
        ], $frames->getValue());

        $hpack = new HPack;

        self::assertTrue($frames->continue());
        $hpackBuffer = $hpack->encode([
            [":status", (string) Status::OK],
            ["content-length", \strlen($body)],
            ["trailer", "expires"],
            ["date", formatDateHeader()],
        ]);
        self::assertSame([
            'length' => \strlen($hpackBuffer),
            'type' => Http2Parser::HEADERS,
            'flags' => Http2Parser::END_HEADERS,
            'stream' => 1,
            'buffer' => $hpackBuffer,
        ], $frames->getValue());

        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => 3,
            'type' => Http2Parser::DATA,
            'flags' => Http2Parser::NO_FLAG,
            'stream' => 1,
            'buffer' => 'foo',
        ], $frames->getValue());

        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => 4,
            'type' => Http2Parser::RST_STREAM,
            'flags' => Http2Parser::NO_FLAG,
            'stream' => 1,
            'buffer' => \pack("N", Http2Parser::INTERNAL_ERROR),
        ], $frames->getValue());

        $request->await();
    }

    public function testPingPong(): void
    {
        $queue = new Queue;
        $this->givenInput(new ReadableStreamChain(
            new ReadableBuffer(
                Http2Parser::PREFACE . self::packFrame("blahbleh", Http2Parser::PING, Http2Parser::NO_FLAG)
            ),
            // Keep stream open, so pings can still be sent
            new ReadableIterableStream($queue->iterate())
        ));

        $ping = null;

        foreach ($this->whenReceivingFrames() as $frame) {
            // ignore settings and window updates...
            if ($frame['type'] === Http2Parser::PING) {
                $ping = $frame;
                break;
            }
        }

        self::assertEquals([
            'length' => 8,
            'type' => Http2Parser::PING,
            'flags' => Http2Parser::ACK,
            'stream' => 0,
            'buffer' => "blahbleh",
        ], $ping);
    }

    public function testFlowControl(): void
    {
        /** @noinspection PhpInternalEntityUsedInspection */
        if (HPackNghttp2::isSupported()) {
            self::markTestSkipped('Not supported with nghttp2, disable ffi for this test.');
        }

        $this->initDriver();
        $request = async(fn () => $this->whenRequestIsReceived());

        $input = new Queue;
        $this->givenInput(new ReadableIterableStream($input->pipe()));
        $frames = $this->whenReceivingFrames();

        $input->push(Http2Parser::PREFACE);

        self::assertTrue($frames->continue());
        $frame = $frames->getValue();
        self::assertSame($frame["type"], Http2Parser::SETTINGS);
        self::assertSame($frame["stream"], 0);

        $input->push(self::packFrame(
            \pack("nN", Http2Parser::INITIAL_WINDOW_SIZE, 66000),
            Http2Parser::SETTINGS,
            Http2Parser::NO_FLAG
        ));
        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => 0,
            'type' => Http2Parser::SETTINGS,
            'flags' => Http2Parser::ACK,
            'stream' => 0,
            'buffer' => "",
        ], $frames->getValue());

        $headers = [
            ":authority" => "localhost",
            ":path" => "/",
            ":scheme" => "http",
            ":method" => "GET",
            "test" => "successful",
        ];
        $input->push(self::packHeader($headers));

        // $onMessage callback should be invoked.
        $queue = new Queue();
        $this->givenNextResponse(new Response(
            Status::OK,
            ["content-type" => "text/html; charset=utf-8"],
            new ReadableIterableStream($queue->pipe())
        ));
        $request->await();

        $hpack = new HPack;
        $hpackBuffer = $hpack->encode([
            [":status", (string) Status::OK],
            ["content-type", "text/html; charset=utf-8"],
            ["date", formatDateHeader()],
        ]);
        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => \strlen($hpackBuffer),
            'type' => Http2Parser::HEADERS,
            'flags' => Http2Parser::END_HEADERS,
            'stream' => 1,
            'buffer' => $hpackBuffer,
        ], $frames->getValue());

        $queue->pushAsync(\str_repeat("_", 66002))->ignore();
        $queue->complete();

        $recv = "";
        while (\strlen($recv) < Http2Driver::DEFAULT_WINDOW_SIZE) {
            self::assertTrue($frames->continue());
            $frame = $frames->getValue();
            $recv .= $frame["buffer"];
            self::assertEquals(Http2Parser::DATA, $frame["type"]);
            self::assertEquals(Http2Parser::NO_FLAG, $frame["flags"]);
            self::assertEquals(1, $frame["stream"]);
        }

        self::assertEquals(Http2Driver::DEFAULT_WINDOW_SIZE, \strlen($recv)); // global window!!

        $chunkSize = 66000 - Http2Driver::DEFAULT_WINDOW_SIZE;
        $input->push(self::packFrame(\pack("N", $chunkSize), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG));

        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => $chunkSize,
            'type' => Http2Parser::DATA,
            'flags' => Http2Parser::NO_FLAG,
            'stream' => 1,
            'buffer' => \str_repeat("_", $chunkSize),
        ], $frames->getValue());

        $input->push(self::packFrame(\pack("N", 4), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG));
        $wasEmpty = false;
        try {
            $frames->continue(new TimeoutCancellation(0));
        } catch (CancelledException) {
            $wasEmpty = true;
        }
        $this->assertTrue($wasEmpty); // global window update alone must not trigger send

        $input->push(self::packFrame(\pack("N", 1), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG, 1));

        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => 1,
            'type' => Http2Parser::DATA,
            'flags' => Http2Parser::NO_FLAG,
            'stream' => 1,
            'buffer' => "_",
        ], $frames->getValue());

        $input->push(self::packFrame(\pack("N", 1), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG, 1));

        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => 1,
            'type' => Http2Parser::DATA,
            'flags' => Http2Parser::NO_FLAG,
            'stream' => 1,
            'buffer' => "_",
        ], $frames->getValue());

        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => 0,
            'type' => Http2Parser::DATA,
            'flags' => Http2Parser::END_STREAM,
            'stream' => 1,
            'buffer' => "",
        ], $frames->getValue());

        $input->push(self::packHeader($headers, false, 3));

        $queue = new Queue();
        $this->givenNextResponse(new Response(
            Status::OK,
            ["content-type" => "text/html; charset=utf-8"],
            new ReadableIterableStream($queue->pipe())
        ));
        $this->whenRequestIsReceived();
        // $onMessage callback should be invoked.

        $hpackBuffer = $hpack->encode([
            [":status", (string) Status::OK],
            ["content-type", "text/html; charset=utf-8"],
            ["date", formatDateHeader()],
        ]);
        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => \strlen($hpackBuffer),
            'type' => Http2Parser::HEADERS,
            'flags' => Http2Parser::END_HEADERS,
            'stream' => 3,
            'buffer' => $hpackBuffer,
        ], $frames->getValue());

        $queue->push("**");

        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => 2,
            'type' => Http2Parser::DATA,
            'flags' => Http2Parser::NO_FLAG,
            'stream' => 3,
            'buffer' => "**",
        ], $frames->getValue());

        $queue->push("*");
        $wasEmpty = false;
        try {
            EventLoop::defer(fn () => null); // TimeoutCancellation is unreferenced...
            $frames->continue(new TimeoutCancellation(0));
        } catch (CancelledException) {
            $wasEmpty = true;
        }
        $this->assertTrue($wasEmpty); // global window too small

        $input->push(self::packFrame(\pack("N", 1), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG));

        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => 1,
            'type' => Http2Parser::DATA,
            'flags' => Http2Parser::NO_FLAG,
            'stream' => 3,
            'buffer' => "*",
        ], $frames->getValue());

        $queue->complete();

        self::assertTrue($frames->continue());
        self::assertSame([
            'length' => 0,
            'type' => Http2Parser::DATA,
            'flags' => Http2Parser::END_STREAM,
            'stream' => 3,
            'buffer' => "",
        ], $frames->getValue());
    }

    public function testPush(): void
    {
        /** @noinspection PhpInternalEntityUsedInspection */
        if (HPackNghttp2::isSupported()) {
            self::markTestSkipped('Not supported with nghttp2, disable ffi for this test.');
        }

        $requests = [];

        $headers = [
            ":authority" => "localhost",
            ":path" => "/base",
            ":scheme" => "http",
            ":method" => "GET",
        ];

        $this->givenInput(new ReadableBuffer(Http2Parser::PREFACE . self::packHeader($headers)));
        $this->givenPush("/absolute/path");
        $this->givenPush("relative/path");
        $this->givenPush("path/with/query?key=value");

        $paths = ["/base", "/absolute/path", "/base/relative/path", "/base/path/with/query"];
        foreach ($paths as $path) {
            $requests[] = $this->whenRequestIsReceived();
        }

        foreach ($requests as $id => $requested) {
            self::assertSame($paths[$id], $requested->getUri()->getPath());
        }

        $request = $requests[\count($requests) - 1];
        self::assertSame("key=value", $request->getUri()->getQuery());
    }

    public function testPingFlood(): void
    {
        $client = $this->createClientMock();
        $client->expects(self::atLeastOnce())
            ->method('close');

        $buffer = Http2Parser::PREFACE;
        $ping = "aaaaaaaa";
        for ($i = 0; $i < 1024; ++$i) {
            $buffer .= self::packFrame($ping++, Http2Parser::PING, Http2Parser::NO_FLAG);
        }

        $this->givenInput(new ReadableBuffer($buffer));

        $this->whenClientIsHandled($client);

        self::assertTrue($this->output->getSink()->isClosed());
        self::assertStringEndsWith(
            \bin2hex(self::packFrame(
                \pack("NN", 0, Http2Parser::ENHANCE_YOUR_CALM) . 'Too many pings',
                Http2Parser::GOAWAY,
                Http2Parser::NO_FLAG
            )),
            \bin2hex(buffer($this->output->getSource()))
        );
    }

    public function testSendingResponseBeforeRequestCompletes(): void
    {
        $headers = [
            ":authority" => ["localhost:8888"],
            ":path" => ["/foo"],
            ":scheme" => ["http"],
            ":method" => ["POST"],
        ];

        $input = Http2Parser::PREFACE;
        $input .= self::packFrame(\pack("N", 100), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG);
        $input .= self::packHeader($headers, true);

        $queue = new Queue;

        $this->givenInput(new ReadableStreamChain(
            new ReadableBuffer($input),
            new ReadableIterableStream($queue->iterate())
        ));

        $request = $this->whenRequestIsReceived();

        $queue->pushAsync(self::packFrame("body-data", Http2Parser::DATA, Http2Parser::END_STREAM, 1));

        $buffer = $request->getBody()->buffer();
        self::assertSame('body-data', $buffer);
    }

    public function testSendingLargeHeaders(): void
    {
        $header = 'x-long-header';
        $value = \str_repeat('.', 10000);

        $headers = [
            ":authority" => ["localhost:8888"],
            ":path" => ["/foo"],
            ":scheme" => ["http"],
            ":method" => ["POST"],
            $header => $value,
        ];

        $input = Http2Parser::PREFACE;
        $input .= self::packFrame(\pack("N", 100), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG);
        $input .= self::packHeader($headers, true);

        $this->givenInput(new ReadableBuffer($input));

        $request = $this->whenRequestIsReceived();

        self::assertSame($value, $request->getHeader($header));
    }

    protected function givenPush(string $uri): void
    {
        $this->pushes[] = $uri;
    }

    private function givenNextResponse(Response $response)
    {
        $this->responses->push($response);
    }

    private function whenReceivingFrames(): ConcurrentIterator
    {
        async(fn () => $this->driver->handleClient(
            $this->createClientMock(),
            $this->input,
            $this->output->getSink(),
        ))->ignore();

        return Pipeline::fromIterable($this->receiveFrames())->getIterator();
    }

    private function receiveFrames(): iterable
    {
        while (true) {
            $frameHeader = $this->outputReader->readLength(9);

            [
                'length' => $frameLength,
                'type' => $frameType,
                'flags' => $frameFlags,
                'id' => $streamId,
            ] = \unpack('Nlength/ctype/cflags/Nid', "\0" . $frameHeader);

            $streamId &= 0x7fffffff;

            $frameBuffer = $frameLength === 0 ? '' : $this->outputReader->readLength($frameLength);

            yield [
                'length' => $frameLength,
                'type' => $frameType,
                'flags' => $frameFlags,
                'stream' => $streamId,
                'buffer' => $frameBuffer,
            ];
        }
    }
}
