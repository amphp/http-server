<?php declare(strict_types=1);

namespace Amp\Http\Server\Test\Driver;

use Amp\ByteStream\Pipe;
use Amp\DeferredFuture;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Driver\Http3Driver;
use Amp\Http\Server\Driver\HttpDriver;
use Amp\Http\Server\Driver\Internal\Http3\Http3ConnectionException;
use Amp\Http\Server\Driver\Internal\Http3\Http3Frame;
use Amp\Http\Server\Driver\Internal\Http3\Http3Parser;
use Amp\Http\Server\Driver\Internal\Http3\Http3Settings;
use Amp\Http\Server\Driver\Internal\Http3\Http3StreamException;
use Amp\Http\Server\Driver\Internal\Http3\Http3Writer;
use Amp\Http\Server\Driver\Internal\Http3\QPack;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Trailers;
use Amp\Quic\Pair\PairConnection;
use Amp\Quic\Pair\PairSocket;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use function Amp\delay;

class Http3DriverTest extends HttpDriverTest
{
    private PairConnection $client;
    private PairConnection $server;
    private Http3Parser $parser;
    private Http3Writer $writer;
    private Http3Driver $driver;
    private QPack $qpack;
    private array $requests = [];
    private array $expectedSettings = [];
    private \SplQueue $responses;
    private ?EventLoop\Suspension $requestSuspension = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initDriver();
        [$this->server, $this->client] = PairConnection::createPair();

        $qpack = $this->qpack = new QPack;
        $this->parser = new Http3Parser($this->client, 0x10000, $qpack);
        $this->writer = (new \ReflectionClass(Http3Writer::class))->newInstanceWithoutConstructor();
        $client = $this->client;
        (function () use ($client, $qpack) {
            $this->qpack = $qpack;
            $this->connection = $client;
        })->call($this->writer);
        $this->responses = new \SplQueue;
        $this->expectedSettings = [
            Http3Settings::MAX_FIELD_SECTION_SIZE->value => HttpDriver::DEFAULT_HEADER_SIZE_LIMIT,
            Http3Settings::ENABLE_CONNECT_PROTOCOL->value => 1,
        ];

        $this->initDriver();
    }

    private function sendSettings($settings = []): void
    {
        (function () use ($settings) {
            $this->settings = $settings;
            $this->startControlStream();
        })->call($this->writer);
    }

    private function initDriver(array $options = []): void
    {
        $this->driver = new Http3Driver(
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

                return $response;
            }),
            $this->createMock(ErrorHandler::class),
            new NullLogger,
            ...$options,
        );
    }

    public function assertParse(array ...$expected): void
    {
        $processor = $this->parser->process();
        try {
            foreach ($expected as $event) {
                $this->assertTrue($processor->continue());
                $got = $processor->getValue();
                [$type] = $event;
                if ($type instanceof Http3Frame) {
                    $this->assertSame($event, $got);
                } else {
                    \array_pop($got); // strip stream
                    $this->assertSame($event, $got);
                }
            }
        } catch (Http3ConnectionException | Http3StreamException $e) {
            $this->assertSame($event, $expected[\array_key_last($expected)]);
            throw $e;
        }
        $this->client->close();
        $this->assertFalse($processor->continue());
    }

    public function doRequest(array $headers, \Closure $afterHeaders, array $expectedRequest, array $responseFrames = [], ?array $response = null): void
    {
        EventLoop::queue(fn () => $this->driver->handleConnection($this->createClientMock(), $this->server));

        $this->sendSettings();
        $stream = $this->client->openStream();
        $this->writer->sendHeaderFrame($stream, $headers);

        $afterHeaders($stream);

        $this->requestSuspension = EventLoop::getSuspension();
        $this->requestSuspension->suspend();

        $request = \array_shift($this->requests);
        $body = $request->getBody()->buffer();
        $trailers = $request->getTrailers()?->await();

        $headers = $request->getHeaders();
        foreach ($headers as $header => $value) {
            if ($header[0] === ":") {
                unset($headers[$header]);
            }
        }

        $defaultPort = $request->getUri()->getScheme() === "https" ? 443 : 80;
        self::assertSame($expectedRequest["protocol"], $request->getProtocolVersion());
        self::assertSame($expectedRequest[":protocol"] ?? "", $request->getProtocol());
        self::assertSame($expectedRequest["method"], $request->getMethod());
        self::assertSame($expectedRequest["uri"], $request->getUri()->getPath());
        self::assertSame($expectedRequest["headers"] ?? [], $headers);
        self::assertSame($expectedRequest["port"] ?? 80, $request->getUri()->getPort() ?: $defaultPort);
        self::assertSame($expectedRequest["host"], $request->getUri()->getHost());
        self::assertSame($expectedRequest["body"] ?? "", $body);
        self::assertSame($expectedRequest["trailers"] ?? [], $trailers ? $trailers->getHeaders() : []);

        if ($response !== null) {
            $responseFragments = [];
            foreach ($this->parser->awaitHttpResponse($stream) as $k => $v) {
                if ($k === Http3Frame::HEADERS && isset($v[0]["date"])) {
                    $v[0]["date"] = ["<fixed>"];
                }
                $responseFragments[] = [$k, $v];
            }
            $this->assertSame($response, $responseFragments);
        }

        $this->assertParse([Http3Frame::SETTINGS, $this->expectedSettings], ...$responseFrames);
    }

    private const SIMPLE_PSEUDO_REQUEST = [
        ":authority" => ["localhost:8888"],
        ":path" => ["/foo"],
        ":scheme" => ["http"],
        ":method" => ["GET"],
    ];

    private const SIMPLE_REQUEST_RECEIVE = [
        "protocol" => "3",
        "method" => "GET",
        "uri" => "/foo",
        "host" => "localhost",
        "port" => 8888,
    ];

    public function testSimpleRequest(): void
    {
        $headers = [...self::SIMPLE_PSEUDO_REQUEST, "test" => ["successful"]];

        $expectations = [
            ...self::SIMPLE_REQUEST_RECEIVE,
            "headers" => ["test" => ["successful"]],
            "body" => "",
        ];

        $this->doRequest($headers, fn (PairSocket $stream) => $stream->end(), $expectations);
    }

    public function testSimpleRequestWithBody(): void
    {
        $headers = self::SIMPLE_PSEUDO_REQUEST;

        $expectations = [
            ...self::SIMPLE_REQUEST_RECEIVE,
            "body" => "body",
            "trailers" => ["trail" => ["er", "header"]]
        ];

        $writer = function (PairSocket $stream) {
            $this->writer->sendData($stream, "bo");
            $this->writer->sendData($stream, "dy");
            $this->writer->sendHeaderFrame($stream, ["trail" => ["er", "header"]]);
            $stream->end();
        };

        $this->doRequest($headers, $writer, $expectations);
    }

    public function testConnectRequest(): void
    {
        $headers = [":authority" => ["localhost:8888"], ":method" => ["CONNECT"], "test" => ["successful"]];

        $expectations = [
            "protocol" => "3",
            "method" => "CONNECT",
            "host" => "localhost",
            "port" => 8888,
            "uri" => "",
            "headers" => ["test" => ["successful"]],
            "body" => "",
        ];

        $this->doRequest($headers, fn (PairSocket $stream) => $stream->end(), $expectations);
    }

    public function testExtendedConnectRequest(): void
    {
        $headers = [...self::SIMPLE_PSEUDO_REQUEST, ":method" => ["CONNECT"], ":protocol" => ["websocket"]];

        $expectations = [
            ...self::SIMPLE_REQUEST_RECEIVE,
            "method" => "CONNECT",
            ":protocol" => "websocket",
            "body" => "",
        ];

        $this->doRequest($headers, fn (PairSocket $stream) => $stream->end(), $expectations);
    }

    public function testInterruptedRequest(): void
    {
        $headers = self::SIMPLE_PSEUDO_REQUEST;

        $expectations = [
            ...self::SIMPLE_REQUEST_RECEIVE,
            "body" => "body",
            "trailers" => ["trail" => ["er", "header"]]
        ];

        $writer = function (PairSocket $stream) {
            $this->writer->sendData($stream, "bo");
            $this->writer->sendData($stream, "dy");
            \Amp\delay(0);
            $stream->resetSending();
        };

        $this->expectException(ClientException::class);
        $this->doRequest($headers, $writer, $expectations);
    }

    public function testResponseSending(): void
    {
        $pipe = new Pipe(1);
        $this->responses->push(new Response(201, ["test" => "successful"], $pipe->getSource()));
        $sink = $pipe->getSink();
        EventLoop::queue(function () use ($sink) {
            $sink->write("a ");
            $sink->write("few ");
            $sink->write("many ");
            $sink->write("bytes");
            $sink->end();
        });
        $this->doRequest(self::SIMPLE_PSEUDO_REQUEST, fn (PairSocket $stream) => $stream->end(), self::SIMPLE_REQUEST_RECEIVE, response: [
            [Http3Frame::HEADERS, [["test" => ["successful"], "date" => ["<fixed>"]], [":status" => "201"]]],
            [Http3Frame::DATA, "a "],
            [Http3Frame::DATA, "few "],
            [Http3Frame::DATA, "many "],
            [Http3Frame::DATA, "bytes"],
        ]);
    }

    public function testTrailerResponseSending(): void
    {
        $response = new Response(201, [], "some body");
        $future = new DeferredFuture;
        $future->complete(["added" => "header"]);
        $response->setTrailers(new Trailers($future->getFuture(), ["added"]));
        $this->responses->push($response);
        $this->doRequest(self::SIMPLE_PSEUDO_REQUEST, fn (PairSocket $stream) => $stream->end(), self::SIMPLE_REQUEST_RECEIVE, response: [
            [Http3Frame::HEADERS, [["content-length" => ["9"], "date" => ["<fixed>"], "trailer" => ["added"]], [":status" => "201"]]],
            [Http3Frame::DATA, "some body"],
            [Http3Frame::HEADERS, [["added" => ["header"]], []]],
        ]);
    }

    public function testUnexpectedPush(): void
    {
        $this->expectException(Http3ConnectionException::class);
        $this->doRequest(self::SIMPLE_PSEUDO_REQUEST, function (PairSocket $stream) {
            $this->writer->sendPushPromiseFrame($stream, 1, []);
        }, []);
    }

    public function testUnexpectedPushCancel(): void
    {
        $this->expectException(ClientException::class);
        $this->doRequest(self::SIMPLE_PSEUDO_REQUEST, function (PairSocket $stream) {
            $this->writer->sendCancelPush(1);
            $stream->end();
        }, []);
    }

    public function testUnexpectedPriorityUpdatePush(): void
    {
        $this->expectException(ClientException::class);
        $this->doRequest(self::SIMPLE_PSEUDO_REQUEST, function (PairSocket $stream) {
            $this->writer->sendPriorityPush($stream->getId(), "u=3, i");
        }, []);
    }

    public function testIgnoredMaxPushId(): void
    {
        $this->doRequest(self::SIMPLE_PSEUDO_REQUEST, function (PairSocket $stream) {
            $this->writer->sendMaxPushId(1);
            $stream->end();
        }, self::SIMPLE_REQUEST_RECEIVE);
    }

    public function testUnexpectedMaxPushId(): void
    {
        $this->expectException(ClientException::class);
        $this->doRequest(self::SIMPLE_PSEUDO_REQUEST, function (PairSocket $stream) {
            $this->writer->sendMaxPushId(1);
            $this->writer->sendMaxPushId(0);
        }, []);
    }

    public function testIgnoredGoaway(): void
    {
        $this->doRequest(self::SIMPLE_PSEUDO_REQUEST, function (PairSocket $stream) {
            $this->writer->sendGoaway(1);
            $stream->end();
        }, self::SIMPLE_REQUEST_RECEIVE);
    }

    public function testUnexpectedGoaway(): void
    {
        $this->expectException(ClientException::class);
        $this->doRequest(self::SIMPLE_PSEUDO_REQUEST, function (PairSocket $stream) {
            $this->writer->sendGoaway(1);
            $this->writer->sendGoaway(0);
        }, []);
    }

    public function testPriorityUpdate(): void
    {
        $this->expectException(ClientException::class);
        $this->doRequest(self::SIMPLE_PSEUDO_REQUEST, function (PairSocket $stream) use (&$streamId) {
            $streamId = $stream->getId();
            $this->writer->sendPriorityRequest(1, "u=2");
        }, []);

        $this->assertSame($this->server->getStream($streamId)->priority, 126);
        $this->assertFalse($this->server->getStream($streamId)->incremental);
    }

    public function testEarlyStop(): void
    {
        $this->driver->stop();

        EventLoop::queue(fn () => $this->driver->handleConnection($this->createClientMock(), $this->server));

        $stream = $this->client->openStream();
        $this->writer->sendHeaderFrame($stream, []);

        $this->assertFalse($this->parser->awaitHttpResponse($stream)->valid());
    }

    public function testStop(): void
    {
        $this->doRequest(self::SIMPLE_PSEUDO_REQUEST, function (PairSocket $stream) use (&$clientStream) {
            \Amp\async(function () use ($stream) {
                \Amp\delay(0);
                EventLoop::queue($stream->end(...));
                $this->driver->stop();
            });

            $clientStream = $stream;
        }, self::SIMPLE_REQUEST_RECEIVE, [[Http3Frame::GOAWAY, 0]]);

        $this->assertFalse($this->parser->awaitHttpResponse($clientStream)->valid());
    }
}
