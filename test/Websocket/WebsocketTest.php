<?php

namespace Aerys\Test\Websocket;

use Aerys\Body;
use Aerys\Client;
use Aerys\DefaultErrorHandler;
use Aerys\ErrorHandler;
use Aerys\Logger;
use Aerys\Request;
use Aerys\Response;
use Aerys\Server;
use Aerys\Websocket\Application;
use Aerys\Websocket\Code;
use Aerys\Websocket\Endpoint;
use Aerys\Websocket\Internal\Rfc6455Gateway;
use Aerys\Websocket\Message;
use Amp\ByteStream\InMemoryStream;
use Amp\Deferred;
use Amp\Delayed;
use Amp\Http\Status;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use Amp\Socket\ClientSocket;
use Amp\Uri\Uri;

class NullApplication implements Application {
    /** @var TestCase */
    public $test;

    /** @var Endpoint */
    public $endpoint;

    public function __construct($test = null) {
        $this->test = $test;
    }

    public function onStart(Endpoint $endpoint) {
        $this->endpoint = $endpoint;
    }

    public function onHandshake(Request $request, Response $response) {
        return $response;
    }

    public function onOpen(int $clientId, Request $request) {
    }

    public function onData(int $clientId, Message $msg) {
    }

    public function onClose(int $clientId, int $code, string $reason) {
    }

    public function onStop() {
    }
}

class WebsocketTest extends TestCase {
    public function assertSocket($expectations, $data) {
        while ($expected = array_shift($expectations)) {
            $op = $expected[0];
            $content = $expected[1] ?? null;

            $this->assertGreaterThanOrEqual(2, \strlen($data));
            $this->assertEquals($op, \ord($data) & 0xF);

            $len = \ord($data[1]);
            if ($len === 0x7E) {
                $len = unpack('n', $data[2] . $data[3])[1];
                $data = substr($data, 4);
            } elseif ($len === 0x7F) {
                $len = unpack('J', substr($data, 2, 8))[1];
                $data = substr($data, 10);
            } else {
                $data = substr($data, 2);
            }

            if (!$expectations) {
                $this->assertEquals($len, \strlen($data));
            }

            if ($content !== null) {
                $this->assertEquals($content, substr($data, 0, $len));
            }

            $data = substr($data, $len);
        }
    }

    public function initEndpoint(Application $application, bool $timeoutTest = false) {
        list($socket, $client) = stream_socket_pair(\stripos(PHP_OS, "win") === 0 ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($client, false);

        $server = $this->createMock(Server::class);
        $gateway = new Rfc6455Gateway($application);

        if ($timeoutTest) {
            // okay, let's cheat a bit in order to properly test timeout...
            (function () {
                $this->now -= 10;
            })->call($gateway);
        }

        yield $gateway->onStart($server, $this->createMock(Logger::class), $this->createMock(ErrorHandler::class));

        $client = $gateway->reapClient(new ClientSocket($client), $this->createMock(Request::class));

        return [$gateway, $client, $socket, $server];
    }

    public function waitOnRead($sock): Promise {
        $deferred = new Deferred;
        Loop::onReadable($sock, function ($watcherId) use ($deferred) {
            Loop::cancel($watcherId);
            $deferred->resolve();
        });

        return $deferred->promise();
    }

    /**
     * @dataProvider provideParsedData
     */
    public function testParseData($data, $func) {
        Loop::run(function () use ($data, $func) {
            /** @var Rfc6455Gateway $gateway */
            list($gateway, $client, $socket, $server) = yield from $this->initEndpoint($ws = new class($this, $func) extends NullApplication {
                public $func;
                public $gen;

                public function __construct($test, $func) {
                    parent::__construct($test);
                    $this->func = $func;
                }

                public function onData(int $clientId, Message $msg) {
                    $this->gen = ($this->func)($clientId, $msg);
                    if ($this->gen instanceof \Generator) {
                        yield from $this->gen;
                    } else {
                        ($this->gen = (function () { yield; })())->next(); // finished generator
                    }
                }
            });

            foreach ($data as list($payload, $terminated)) {
                $gateway->onParsedData($client, Rfc6455Gateway::OP_TEXT, $payload, $terminated);
            }

            $this->assertFalse($ws->gen->valid());

            yield $gateway->onStop($server);

            Loop::stop();
        });
    }

    public function provideParsedData() {
        return [
            [[
                ["foo", true]
            ], function (int $clientId, Message $message) {
                $this->assertSame("foo", yield $message);
            }],
            [[
                ["foo", false],
                ["bar", true],
                ["baz", true]
            ], function (int $clientId, Message $message) {
                static $call = 0;

                if (++$call === 1) {
                    $expected = ["foo", "bar"];
                    while (($chunk = yield $message->read()) !== null) {
                        $this->assertSame(\array_shift($expected), $chunk);
                    }
                } else {
                    $this->assertEquals("baz", yield $message);
                }
            }]
        ];
    }

    /**
     * @dataProvider provideErrorEvent
     */
    public function testAppErrorClosesConnection(string $method, array $call = null) {
        Loop::run(function () use ($method, $call) {
            $application = $this->createMock(Application::class);
            $application->expects($this->once())
                ->method($method)
                ->willReturnCallback(function () {
                    throw new \Exception;
                });
            $application->method("onHandshake")
                ->willReturnArgument(1);

            list($gateway, $client, $socket) = yield from $this->initEndpoint($application, $timeoutTest = true);

            if ($call !== null) {
                list($method, $args) = $call;
                $gateway->$method($client, ...$args);
            }

            // Time to read and write.
            yield new Delayed(10);

            $this->assertSocket([[Rfc6455Gateway::OP_CLOSE]], stream_get_contents($socket));

            Loop::stop();
        });
    }

    public function provideErrorEvent(): array {
        return [
            ["onOpen", null],
            ["onData", ["onParsedData", [Rfc6455Gateway::OP_TEXT, "data", true]]],
            ["onClose", ["onParsedControlFrame", [Rfc6455Gateway::OP_CLOSE, "\xFF\xFF"]]],
            ["onClose", ["onParsedError", [Code::PROTOCOL_ERROR, ""]]]
        ];
    }

    /**
     * @param Request $request Request initiating the handshake.
     * @param int     $status Expected status code.
     * @param array   $expectedHeaders Expected response headers.
     *
     * @dataProvider provideHandshakes
     */
    public function testHandshake(Request $request, int $status, array $expectedHeaders = []) {
        Loop::run(function () use ($request, $status, $expectedHeaders) {
            $server = $this->createMock(Server::class);
            $logger = $this->createMock(Logger::class);
            $application = $this->createMock(Application::class);

            $application->expects($status === Status::SWITCHING_PROTOCOLS ? $this->once() : $this->never())
                ->method("onHandshake")
                ->willReturnArgument(1);

            $gateway = new Rfc6455Gateway($application);

            yield $gateway->onStart($server, $logger, new DefaultErrorHandler);

            /** @var Response $response */
            $response = yield $gateway->respond($request);
            $this->assertEquals($expectedHeaders, array_intersect_key($response->getHeaders(), $expectedHeaders));

            if ($status === Status::SWITCHING_PROTOCOLS) {
                $this->assertNull(yield $response->getBody()->read());
            }

            yield $gateway->onStop($server);
        });
    }

    public function provideHandshakes(): array {
        $testCases = [];

        $headers = [
            "host" => ["localhost"],
            "sec-websocket-key" => ["x3JJHMbDL1EzLkh9GBhXDw=="],
            "sec-websocket-version" => ["13"],
            "upgrade" => ["websocket"],
            "connection" => ["upgrade"]
        ];

        // 0 ----- valid Handshake request -------------------------------------------------------->
        $request = new Request($this->createMock(Client::class), "GET", new Uri("/"), $headers);
        $testCases[] = [$request, Status::SWITCHING_PROTOCOLS, [
            "upgrade" => ["websocket"],
            "connection" => ["upgrade"],
            "sec-websocket-accept" => ["HSmrc0sMlYUkAGmm5OPpG2HaGWk="]
        ]];

        // 1 ----- error conditions: Handshake with POST method ----------------------------------->
        $request = new Request($this->createMock(Client::class), "POST", new Uri("/"), $headers);
        $testCases[] = [$request, Status::METHOD_NOT_ALLOWED, ["allow" => ["GET"]]];

        // 2 ----- error conditions: Handshake with 1.0 protocol ---------------------------------->
        $request = new Request($this->createMock(Client::class), "GET", new Uri("/"), $headers, null, "1.0");
        $testCases[] = [$request, Status::HTTP_VERSION_NOT_SUPPORTED];

        // 3 ----- error conditions: Handshake with non-empty body -------------------------------->
        $body = new Body(new InMemoryStream("Non-empty body"));
        $request = new Request($this->createMock(Client::class), "GET", new Uri("/"), $headers, $body);
        $testCases[] = [$request, Status::BAD_REQUEST];

        // 4 ----- error conditions: Upgrade: Websocket header required --------------------------->
        $invalidHeaders = $headers;
        $invalidHeaders["upgrade"] = ["no websocket!"];
        $request = new Request($this->createMock(Client::class), "GET", new Uri("/"), $invalidHeaders, $body);
        $testCases[] = [$request, Status::UPGRADE_REQUIRED];

        // 5 ----- error conditions: Connection: Upgrade header required -------------------------->
        $invalidHeaders = $headers;
        $invalidHeaders["connection"] = ["no upgrade!"];
        $request = new Request($this->createMock(Client::class), "GET", new Uri("/"), $invalidHeaders, $body);
        $testCases[] = [$request, Status::UPGRADE_REQUIRED];

        // 6 ----- error conditions: Sec-Websocket-Key header required ---------------------------->
        $invalidHeaders = $headers;
        unset($invalidHeaders["sec-websocket-key"]);
        $request = new Request($this->createMock(Client::class), "GET", new Uri("/"), $invalidHeaders, $body);
        $testCases[] = [$request, Status::BAD_REQUEST];

        // 7 ----- error conditions: Sec-Websocket-Version header must be 13 ---------------------->
        $invalidHeaders = $headers;
        $invalidHeaders["sec-websocket-version"] = ["12"];
        $request = new Request($this->createMock(Client::class), "GET", new Uri("/"), $invalidHeaders, $body);
        $testCases[] = [$request, Status::BAD_REQUEST];

        return $testCases;
    }

    public function runClose(callable $closeCallback) {
        Loop::run(function () use ($closeCallback) {
            list($gateway, $client, $sock, $server) = yield from $this->initEndpoint(
                $ws = new class($this) extends NullApplication {
                    public $closed = false;

                    public function onClose(int $clientId, int $code, string $reason) {
                        $this->closed = $code;
                    }
                }
            );

            yield from $closeCallback($gateway, $sock, $ws, $client);

            Loop::stop();
        });
    }

    public function testCloseFrame() {
        $this->runClose(function (Rfc6455Gateway $gateway, $sock, $ws, $client) {
            $gateway->onParsedControlFrame($client, Rfc6455Gateway::OP_CLOSE, "");

            // Time to read, write, and close.
            yield new Delayed(10);

            $this->assertEquals(Code::NONE, $ws->closed);
            $this->assertSocket([[Rfc6455Gateway::OP_CLOSE, ""]], stream_get_contents($sock));
        });
    }

    public function testCloseWithStatus() {
        $this->runClose(function (Rfc6455Gateway $gateway, $sock, $ws, $client) {
            $gateway->onParsedControlFrame($client, Rfc6455Gateway::OP_CLOSE, pack("n", Code::GOING_AWAY));

            // Time to read, write, and close.
            yield new Delayed(10);

            $this->assertEquals(Code::GOING_AWAY, $ws->closed);
            $this->assertSocket([[Rfc6455Gateway::OP_CLOSE, pack("n", Code::GOING_AWAY)]], stream_get_contents($sock));
        });
    }

    public function testCloseFrameWithHalfClose() {
        $this->runClose(function (Rfc6455Gateway $gateway, $sock, $ws, $client) {
            // Fill the buffer to have the server not write the close frame immediately
            $bytes = @fwrite($client->socket, str_repeat("*", 1 << 20));

            $gateway->onParsedControlFrame($client, Rfc6455Gateway::OP_CLOSE, "");
            stream_socket_shutdown($sock, STREAM_SHUT_WR);
            stream_get_contents($sock, $bytes);

            // Time to read, write, and close.
            yield new Delayed(10);

            $this->assertEquals(Code::NONE, $ws->closed);
            $this->assertSocket([[Rfc6455Gateway::OP_CLOSE, ""]], stream_get_contents($sock));
        });
    }

    public function testHalfClose() {
        $this->runClose(function (Rfc6455Gateway $gateway, $sock, $ws, $client) {
            stream_socket_shutdown($sock, STREAM_SHUT_WR);

            // Time to read, write, and close.
            yield new Delayed(10);

            $this->assertEquals(Code::ABNORMAL_CLOSE, $ws->closed);
            $this->assertEquals("", stream_get_contents($sock));
        });
    }

    public function testIOClose() {
        $this->runClose(function (Rfc6455Gateway $gateway, $sock, $ws, $client) {
            fclose($sock);

            // Time to read, write, and close.
            yield new Delayed(10);

            $this->assertEquals(Code::ABNORMAL_CLOSE, $ws->closed);
        });
    }

    public function testIORead() {
        Loop::run(function () {
            list(, $client, $sock) = yield from $this->initEndpoint(new NullApplication);

            fwrite($sock, WebsocketParserTest::compile(Rfc6455Gateway::OP_PING, true, "foo"));

            yield $this->waitOnRead($sock);
            $client->socket->close();

            $this->assertSocket([[Rfc6455Gateway::OP_PONG, "foo"]], stream_get_contents($sock));

            Loop::stop();
        });
    }

    public function testMultiWrite() {
        Loop::run(function () {
            /** @var Rfc6455Gateway $gateway */
            list($gateway, $client, $sock) = yield from $this->initEndpoint($ws = new class($this) extends NullApplication {
                public function onData(int $clientId, Message $msg) {
                    // Fill send buffer
                    $this->endpoint->broadcast("foo".str_repeat("*", 1 << 20));
                    $this->endpoint->send("bar", $clientId);
                    yield $this->endpoint->multicast("baz", [$clientId]);
                    $this->endpoint->close($clientId);
                }
            });

            $gateway->setOption("autoFrameSize", 10 + (1 << 20));
            $gateway->onParsedData($client, Rfc6455Gateway::OP_BIN, "start...", true);
            $gateway->onParsedControlFrame($client, Rfc6455Gateway::OP_PING, "pingpong");

            stream_set_blocking($sock, false);

            $data = "";
            do {
                yield $this->waitOnRead($sock); // to have it read and parsed...
                $data .= fread($sock, 1024);
            } while (!feof($sock));

            $this->assertSocket([
                [Rfc6455Gateway::OP_TEXT, "foo".str_repeat("*", 1 << 20)],
                [Rfc6455Gateway::OP_PONG, "pingpong"],
                [Rfc6455Gateway::OP_TEXT, "bar"],
                [Rfc6455Gateway::OP_TEXT, "baz"],
                [Rfc6455Gateway::OP_CLOSE],
            ], $data);

            Loop::stop();
        });
    }

    public function testFragmentation() {
        Loop::run(function () {
            /** @var Rfc6455Gateway $gateway */
            list($gateway, $client, $sock, $server) = yield from $this->initEndpoint(new NullApplication);

            $gateway->broadcast(str_repeat("*", 131046), true)->onResolve(function ($exception) use ($sock, $server) {
                stream_socket_shutdown($sock, STREAM_SHUT_WR);
                if ($exception) {
                    throw $exception;
                }
            });

            $data = "";
            do {
                yield $this->waitOnRead($sock); // to have it read and parsed...
                $data .= $x = fread($sock, 8192);
            } while ($x != "" || !feof($sock));

            $this->assertSocket([[Rfc6455Gateway::OP_BIN, str_repeat("*", 65523)], [Rfc6455Gateway::OP_CONT, str_repeat("*", 65523)]], $data);

            Loop::stop();
        });
    }

    public function testSinglePong() {
        Loop::run(function () {
            /** @var Rfc6455Gateway $gateway */
            list($gateway, $client, $socket, $server) = yield from $this->initEndpoint(new NullApplication);

            $client->pingCount = 2;
            $gateway->onParsedControlFrame($client, Rfc6455Gateway::OP_PONG, "1");

            $this->assertEquals(1, $client->pongCount);

            $server->state = Server::STOPPED;
            yield $gateway->onStop($server);

            Loop::stop();
        });
    }
}
