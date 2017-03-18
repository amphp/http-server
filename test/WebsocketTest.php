<?php

namespace Aerys\Test;

use Aerys\{
    Client,
    InternalRequest,
    Logger,
    NullBody,
    Request,
    Response,
    Server,
    StandardRequest,
    StandardResponse,
    Websocket,
    Websocket\Rfc6455Gateway,
    const HTTP_STATUS
};
use Amp\{ Deferred, Emitter, Loop, Message, Pause };

class NullWebsocket implements Websocket {
    public $test;
    public $endpoint;
    public function __construct($test = null) { $this->test = $test; }
    public function onStart(Websocket\Endpoint $endpoint) { $this->endpoint = $endpoint; }
    public function onHandshake(Request $request, Response $response) { }
    public function onOpen(int $clientId, $handshakeData) { }
    public function onData(int $clientId, Websocket\Message $msg) { }
    public function onClose(int $clientId, int $code, string $reason) { }
    public function onStop() { }
}

class WebsocketTest extends \PHPUnit_Framework_TestCase {
    function assertSocket($expectations, $data) {
        while ($expected = array_shift($expectations)) {
            $op = $expected[0];
            $content = $expected[1] ?? null;
            $this->assertGreaterThanOrEqual(2, strlen($data));
            $this->assertEquals($op, ord($data) & 0xF);
            $len = ord($data[1]);
            if ($len == 0x7E) {
                $len = unpack('n', $data[2] . $data[3])[1];
                $data = substr($data, 4);
            } elseif ($len == 0x7F) {
                $len = unpack('J', substr($data, 2, 8));
                $data = substr($data, 10);
            } else {
                $data = substr($data, 2);
            }
            if (!$expectations) {
                $this->assertEquals($len, strlen($data));
            }
            if ($content !== null) {
                $this->assertEquals($content, substr($data, 0, $len));
            }
            $data = substr($data, $len);
        }
    }

    function initEndpoint($ws, $timeoutTest = false) {
        $ireq = new InternalRequest();
        $ireq->client = $client = new Client;
        list($sock, $client->socket) = stream_socket_pair(\stripos(PHP_OS, "win") === 0 ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($client->socket, false);
        $server = new class extends Server {
            public $state;
            public $requireClientFree = false;
            public function __construct() { }
            public function state(): int { return $this->state; }
        };
        $client->exporter = function ($_client) use ($client, $server) {
            $this->assertSame($client, $_client);
            $dtor = new class { public $test; public $server; function __destruct() { if ($this->server->requireClientFree) $this->test->fail("Expected client to be killed, but 'decrementer' never called"); } };
            $dtor->test = $this;
            $dtor->server = $server;
            return function() use ($dtor, $client) {
                $this->assertTrue($dtor->server->requireClientFree);
                $dtor->server->requireClientFree = false;
                fclose($client->socket);
            };
        };

        $logger = new class extends Logger { protected function output(string $message) { /* /dev/null */} };
        $gateway = new Rfc6455Gateway($logger, $ws);

        if ($timeoutTest) {
            // okay, let's cheat a bit in order to properly test timeout...
            (function () {
                $this->now -= 10;
            })->call($gateway);
        }

        $server->state = Server::STARTING;
        yield $gateway->update($server);
        $server->state = Server::STARTED;
        yield $gateway->update($server);
        $client = $gateway->reapClient("idiotic parameter...", $ireq);

        return [$gateway, $client, $sock, $server];
    }

    function waitOnRead($sock) {
        $deferred = new Deferred;
        $watcher = Loop::onReadable($sock, [$deferred, "resolve"]);
        $promise = $deferred->promise();
        $promise->when(function() use ($watcher) { Loop::cancel($watcher); });
        return $promise;
    }

    function triggerTimeout(Rfc6455Gateway $gateway) {
        (function() {
            $this->timeout();
        })->call($gateway);
    }

    function testFullSequence() {
        Loop::run(function() {
            list($gateway, $client, $sock, $server) = yield from $this->initEndpoint(new NullWebsocket);
            $server->requireClientFree = true;
            $server->state = Server::STOPPING;
            yield $gateway->update($server);
            $server->state = Server::STOPPED;
            yield $gateway->update($server);
        });
    }

    /**
     * @dataProvider provideParsedData
     */
    function testParseData($data, $func) {
        Loop::run(function() use ($data, $func) {
            list($gateway, $client, $sock, $server) = yield from $this->initEndpoint($ws = new class($this, $func) extends NullWebsocket {
                public $func;
                public $gen;
                function __construct($test, $func) { parent::__construct($test); $this->func = $func; }
                function onData(int $clientId, Websocket\Message $msg) {
                    $this->gen = ($this->func)($clientId, $msg);
                    if ($this->gen instanceof \Generator) {
                        yield from $this->gen;
                    } else {
                        ($this->gen = (function(){ yield; })())->next(); // finished generator
                    }
                }
            });
            foreach ($data as $datum) {
                list($payload, $terminated) = $datum;
                $gateway->onParsedData($client, $payload, false, $terminated);
            }
            $this->assertFalse($ws->gen->valid());

            Loop::stop();
        });
    }

    function provideParsedData() {
        return [
            [[
                ["foo", true]
            ], function ($clientId, $msg) {
                $this->assertEquals("foo", yield $msg);
            }],
            [[
                ["foo", false],
                ["bar", true],
                ["baz", true]
            ], function ($clientId, $msg) {
                static $call = 0;
                if (++$call == 1) {
                    $this->assertTrue(yield $msg->valid());
                    $this->assertEquals("foo", $msg->consume());
                    $this->assertTrue(yield $msg->valid());
                    $this->assertEquals("bar", $msg->consume());
                    $this->assertFalse(yield $msg->valid());
                } else {
                    $this->assertEquals("baz", yield $msg);
                }
            }]
        ];
    }

    /**
     * @dataProvider provideErrorEvent
     */
    function testAppError($method, $call) {
        Loop::run(function() use ($method, $call) {
            $ws = $this->createMock('Aerys\Websocket');
            $ws->expects($this->once())
                ->method($method)
                ->willReturnCallback(function() {
                    throw new \Exception;
                });
            list($gateway, $client, $sock, $server) = yield from $this->initEndpoint($ws, $timeoutTest = true);

            if ($call !== null) {
                list($method, $args) = $call;
                $gateway->$method($client, ...$args);
            }

            $server->requireClientFree = true;
            if ($client->writeBuffer != "") {
                $gateway->onWritable($client->writeWatcher, $client->socket, $client);
            }
            $this->triggerTimeout($gateway);

            $this->assertSocket([[Rfc6455Gateway::OP_CLOSE]], stream_get_contents($sock));
            Loop::stop();
        });
    }

    function provideErrorEvent() {
        return [
            ["onOpen", null],
            ["onData", ["onParsedData", ["data", false, true]]],
            ["onClose", ["onParsedControlFrame", [Rfc6455Gateway::OP_CLOSE, "\xFF\xFF"]]],
            ["onClose", ["onParsedError", [Websocket\Code::PROTOCOL_ERROR, ""]]]
        ];
    }

    /**
     * @dataProvider provideHandshakes
     */
    function testHandshake($ireq, $expected) {
        $logger = new class extends Logger { protected function output(string $message) { /* /dev/null */} };
        $ws = $this->createMock('Aerys\Websocket');
        $ws->expects($expected[":status"] === 101 ? $this->once() : $this->never())
            ->method("onHandshake");
        $gateway = new Rfc6455Gateway($logger, $ws);
        $gateway(new StandardRequest($ireq), new StandardResponse((function () use (&$headers, &$body) {
            $headers = yield;
            $body = yield;
        })(), $ireq->client))->next();

        $this->assertEquals($expected, array_intersect_key($headers, $expected));
        if ($expected[":status"] === 101) {
            $this->assertNull($body);
        }
    }

    function provideHandshakes() {
        $return = [];

        // 0 ----- valid Handshake request -------------------------------------------------------->
        $ireq = new InternalRequest;
        $ireq->client = new Client;
        $ireq->method = "GET";
        $ireq->protocol = "1.1";
        $ireq->headers = [
            "host" => ["localhost"],
            "sec-websocket-key" => ["x3JJHMbDL1EzLkh9GBhXDw=="],
            "sec-websocket-version" => ["13"],
            "upgrade" => ["websocket"],
            "connection" => ["upgrade"]
        ];
        $ireq->body = new NullBody;
        $return[] = [$ireq, [":status" => \Aerys\HTTP_STATUS["SWITCHING_PROTOCOLS"], "upgrade" => ["websocket"], "connection" => ["upgrade"], "sec-websocket-accept" => ["HSmrc0sMlYUkAGmm5OPpG2HaGWk="]]];

        // 1 ----- error conditions: Handshake with POST method ----------------------------------->

        $_ireq = clone $ireq;
        $_ireq->method = "POST";
        $return[] = [$_ireq, [":status" => HTTP_STATUS["METHOD_NOT_ALLOWED"], "allow" => ["GET"]]];

        // 2 ----- error conditions: Handshake with 1.0 protocol ---------------------------------->

        $_ireq = clone $ireq;
        $_ireq->protocol = "1.0";
        $return[] = [$_ireq, [":status" => HTTP_STATUS["HTTP_VERSION_NOT_SUPPORTED"]]];

        // 3 ----- error conditions: Handshake with non-empty body -------------------------------->

        $_ireq = clone $ireq;
        $_ireq->body = new Message((new Emitter)->stream());
        $return[] = [$_ireq, [":status" => HTTP_STATUS["BAD_REQUEST"]]];

        // 4 ----- error conditions: Upgrade: Websocket header required --------------------------->

        $_ireq = clone $ireq;
        $_ireq->headers["upgrade"] = ["no websocket!"];
        $return[] = [$_ireq, [":status" => HTTP_STATUS["UPGRADE_REQUIRED"]]];

        // 5 ----- error conditions: Connection: Upgrade header required -------------------------->

        $_ireq = clone $ireq;
        $_ireq->headers["connection"] = ["no upgrade!"];
        $return[] = [$_ireq, [":status" => HTTP_STATUS["UPGRADE_REQUIRED"]]];

        // 6 ----- error conditions: Sec-Websocket-Key header required ---------------------------->

        $_ireq = clone $ireq;
        unset($_ireq->headers["sec-websocket-key"]);
        $return[] = [$_ireq, [":status" => HTTP_STATUS["BAD_REQUEST"]]];

        // 7 ----- error conditions: Sec-Websocket-Version header must be 13 ---------------------->

        $_ireq = clone $ireq;
        $_ireq->headers["sec-websocket-version"] = ["12"];
        $return[] = [$_ireq, [":status" => HTTP_STATUS["BAD_REQUEST"]]];

        // x -------------------------------------------------------------------------------------->

        return $return;
    }

    function runClose(callable $closeCb) {
        Loop::run(function() use ($closeCb) {
            list($gateway, $client, $sock, $server) = yield from $this->initEndpoint($ws = new class($this) extends NullWebsocket {
                public $closed = false;
                function onClose(int $clientId, int $code, string $reason) {
                    $this->closed = $code;
                }
            });
            $server->requireClientFree = true;
            yield from $closeCb($gateway, $sock, $ws, $client);
            Loop::stop();
        });
    }

    function testCloseFrame() {
        $this->runClose(function ($gateway, $sock, $ws, $client) {
            $gateway->onParsedControlFrame($client, Rfc6455Gateway::OP_CLOSE, "");
            yield new Pause(10); // Time to read, write, and close.
            $this->assertEquals(Websocket\Code::NONE, $ws->closed);
            $this->assertSocket([[Rfc6455Gateway::OP_CLOSE, ""]], stream_get_contents($sock));
        });
    }

    function testCloseWithStatus() {
        $this->runClose(function ($gateway, $sock, $ws, $client) {
            $gateway->onParsedControlFrame($client, Rfc6455Gateway::OP_CLOSE, pack("n", Websocket\Code::GOING_AWAY));
            yield new Pause(10); // Time to read, write, and close.
            $this->assertEquals(Websocket\Code::GOING_AWAY, $ws->closed);
            $this->assertSocket([[Rfc6455Gateway::OP_CLOSE, pack("n", Websocket\Code::GOING_AWAY)]], stream_get_contents($sock));
        });
    }

    function testIOClose() {
        $this->runClose(function ($gateway, $sock, $ws, $client) {
            fclose($sock);
            yield new Pause(10); // Time to read, write, and close.
            $this->assertEquals(Websocket\Code::ABNORMAL_CLOSE, $ws->closed);
        });
    }

    function testIORead() {
        Loop::run(function () {
            list($gateway, $client, $sock, $server) = yield from $this->initEndpoint(new NullWebsocket);
            fwrite($sock, WebsocketParserTest::compile(Rfc6455Gateway::OP_PING, true, "foo"));
            yield $this->waitOnRead($sock);
            fclose($client->socket);
            $this->assertSocket([[Rfc6455Gateway::OP_PONG, "foo"]], stream_get_contents($sock));

            Loop::stop();
        });
    }

    function testMultiWrite() {
        Loop::run(function() {
            list($gateway, $client, $sock, $server) = yield from $this->initEndpoint($ws = new class($this) extends NullWebsocket {
                function onData(int $clientId, Websocket\Message $msg) {
                    $this->endpoint->broadcast("foo".str_repeat("*", 65528 /* fill buffer */));
                    $this->endpoint->send("bar", $clientId);
                    yield $this->endpoint->multicast("baz", [$clientId]);
                    $this->endpoint->close($clientId);
                }
            });
            $gateway->onParsedData($client, "start...", true, true);
            $gateway->onParsedControlFrame($client, Rfc6455Gateway::OP_PING, "pingpong");
            stream_set_blocking($sock, false);
            $data = "";
            do {
                yield $this->waitOnRead($sock); // to have it read and parsed...
                $data .= fread($sock, 1024);
            } while (!feof($sock));
            $this->assertSocket([
                [Rfc6455Gateway::OP_TEXT, "foo".str_repeat("*", 65528)],
                [Rfc6455Gateway::OP_TEXT, "bar"],
                [Rfc6455Gateway::OP_PONG, "pingpong"],
                [Rfc6455Gateway::OP_TEXT, "baz"],
                [Rfc6455Gateway::OP_CLOSE],
            ], $data);
    
            Loop::stop();
        });
    }

    function testFragmentation() {
        Loop::run(function () {
            list($endpoint, $client, $sock, $server) = yield from $this->initEndpoint(new NullWebsocket);
            $endpoint->broadcast(str_repeat("*", 131046), true)->when(function() use ($sock, $server) {
                stream_socket_shutdown($sock, STREAM_SHUT_WR);
                $server->requireClientFree = true;
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

    function testSinglePong() {
        Loop::run(function () {
            list($gateway, $client, $sock, $server) = yield from $this->initEndpoint(new NullWebsocket);
            $client->pingCount = 2;
            $gateway->onParsedControlFrame($client, Rfc6455Gateway::OP_PONG, "1");
            $this->assertEquals(1, $client->pongCount);
    
            Loop::stop();
        });
    }
}