<?php

namespace Aerys\Test;

use Aerys\Body;
use Aerys\Client;
use Aerys\ClientException;
use Aerys\InternalRequest;
use Aerys\Logger;
use Aerys\NullBody;
use Aerys\Request;
use Aerys\Response;
use Aerys\Server;
use Aerys\StandardRequest;
use Aerys\StandardResponse;
use Aerys\Websocket;
use Aerys\Websocket\Rfc6455Endpoint;
use const Aerys\HTTP_STATUS;
use Amp\Deferred;

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
        list($sock, $client->socket) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($client->socket, false);
        $server = new class extends Server {
            public $state;
            public $allowKill = false;
            public function __construct() { }
            public function state(): int { return $this->state; }
        };
        $client->exporter = function ($_client) use ($client, $server) {
            $this->assertSame($client, $_client);
            $dtor = new class { public $test; public $server; function __destruct() { if ($this->server->allowKill) $this->test->fail("Expected client to be killed, but 'decrementer' never called"); } };
            $dtor->test = $this;
            $dtor->server = $server;
            return function() use ($dtor, $client) {
                $this->assertTrue($dtor->server->allowKill);
                $dtor->server->allowKill = false;
                fclose($client->socket);
            };
        };

        $logger = new class extends Logger { protected function output(string $message) { /* /dev/null */} };
        $endpoint = new Rfc6455Endpoint($logger, $ws);

        if ($timeoutTest) {
            // okay, let's cheat a bit in order to properly test timeout...
            (function () {
                $this->now -= 10;
            })->call($endpoint);
        }

        $server->state = Server::STARTING;
        yield $endpoint->update($server);
        $server->state = Server::STARTED;
        yield $endpoint->update($server);
        $client = $endpoint->reapClient("idiotic parameter...", $ireq);

        return [$endpoint, $client, $sock, $server];
    }

    function waitOnRead($sock) {
        $deferred = new Deferred;
        $watcher = \Amp\onReadable($sock, [$deferred, "succeed"]);
        return $deferred->promise()->when(function() use ($watcher) { \Amp\cancel($watcher); });
    }

    function triggerTimeout(Rfc6455Endpoint $endpoint) {
        (function() {
            $this->timeout();
        })->call($endpoint);
    }

    function testFullSequence() {
        \Amp\run(function() {
            list($endpoint, $client, $sock, $server) = yield from $this->initEndpoint(new NullWebsocket);
            $server->allowKill = true;
            $server->state = Server::STOPPING;
            yield $endpoint->update($server);
            $server->state = Server::STOPPED;
            yield $endpoint->update($server);
        });
        \Amp\reactor(\Amp\driver());
    }

    /**
     * @dataProvider provideParsedData
     */
    function testParseData($data, $func) {
        \Amp\run(function() use ($data, $func) {
            list($endpoint, $client, $sock, $server) = yield from $this->initEndpoint($ws = new class($this, $func) extends NullWebsocket {
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
                $endpoint->onParse($datum, $client);
            }
            $this->assertFalse($ws->gen->valid());

            \Amp\stop();
        });
        \Amp\reactor(\Amp\driver());
    }

    function provideParsedData() {
        return [
            [[
                [Rfc6455Endpoint::DATA, "foo", true]
            ], function ($clientId, $msg) {
                $this->assertEquals("foo", yield $msg);
            }],
            [[
                [Rfc6455Endpoint::DATA, "foo", false],
                [Rfc6455Endpoint::DATA, "bar", true],
                [Rfc6455Endpoint::DATA, "baz", true]
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
        \Amp\run(function() use ($method, $call) {
            $ws = $this->getMock('Aerys\Websocket');
            $ws->expects($this->once())
                ->method($method)
                ->willReturnCallback(function() {
                    throw new \Exception;
                });
            list($endpoint, $client, $sock, $server) = yield from $this->initEndpoint($ws, $timeoutTest = true);

            if ($call !== null) {
                list($method, $args) = $call;
                $endpoint->$method(...$args, ...[$client]); // WTF? ... Fatal error: cannot use positional argument after argument unpacking
            }

            $server->allowKill = true;
            if ($client->writeBuffer != "") {
                $endpoint->onWritable($client->writeWatcher, $client->socket, $client);
            }
            $this->triggerTimeout($endpoint);

            $this->assertSocket([[Rfc6455Endpoint::OP_CLOSE]], stream_get_contents($sock));
            \Amp\stop();
        });
        \Amp\reactor(\Amp\driver());
    }

    function provideErrorEvent() {
        return [
            ["onOpen", null],
            ["onData", ["onParse", [[Rfc6455Endpoint::DATA, "data", true]]]],
            ["onClose", ["onParse", [[Rfc6455Endpoint::CONTROL, "\xFF\xFF", Rfc6455Endpoint::OP_CLOSE]]]],
            ["onClose", ["onParse", [[Rfc6455Endpoint::ERROR, "", Websocket\Code::PROTOCOL_ERROR]]]]
        ];
    }

    /**
     * @dataProvider provideHandshakes
     */
    function testHandshake($ireq, $expected) {
        $logger = new class extends Logger { protected function output(string $message) { /* /dev/null */} };
        $ws = $this->getMock('Aerys\Websocket');
        $ws->expects($expected[":status"] === 101 ? $this->once() : $this->never())
            ->method("onHandshake");
        $endpoint = new Rfc6455Endpoint($logger, $ws);
        $endpoint(new StandardRequest($ireq), new StandardResponse((function () use (&$headers, &$body) {
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
        $_ireq->body = new Body((new Deferred)->promise());
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

    function testIOClose() {
        \Amp\run(function() {
            list($endpoint, $client, $sock, $server) = yield from $this->initEndpoint($ws = new class($this) extends NullWebsocket {
                function onData(int $clientId, Websocket\Message $msg) {
                    try {
                        yield $msg;
                    } catch (\Throwable $e) {
                        $this->test->assertInstanceOf(ClientException::class, $e);
                    } finally {
                        if (!isset($e)) {
                            $this->test->fail("Expected ClientException was not thrown");
                        }
                    }
                }
            });
            $endpoint->onParse([Rfc6455Endpoint::DATA, "foo", false], $client);
            fclose($sock);
            $server->allowKill = true;
            yield;yield; // to have it read and closed...

            \Amp\stop();
        });
        \Amp\reactor(\Amp\driver());
    }

    function testIORead() {
        \Amp\run(function () {
            list($endpoint, $client, $sock, $server) = yield from $this->initEndpoint(new NullWebsocket);
            fwrite($sock, WebsocketParserTest::compile(Rfc6455Endpoint::OP_PING, true, "foo"));
            yield $this->waitOnRead($sock);
            fclose($client->socket);
            $this->assertSocket([[Rfc6455Endpoint::OP_PONG, "foo"]], stream_get_contents($sock));

            \Amp\stop();
        });
        \Amp\reactor(\Amp\driver());
    }

    function testMultiWrite() {
        \Amp\run(function() {
            list($endpoint, $client, $sock, $server) = yield from $this->initEndpoint($ws = new class($this) extends NullWebsocket {
                function onData(int $clientId, Websocket\Message $msg) {
                    $this->endpoint->send(null, "foo".str_repeat("*", 65528 /* fill buffer */));
                    $this->endpoint->send($clientId, "bar");
                    yield $this->endpoint->send([$clientId], "baz");
                    $this->endpoint->close($clientId);
                }
            });
            $endpoint->onParse([Rfc6455Endpoint::DATA, true, "start..."], $client);
            $endpoint->onParse([Rfc6455Endpoint::CONTROL, "pingpong", Rfc6455Endpoint::OP_PING], $client);
            stream_set_blocking($sock, false);
            $data = "";
            do {
                yield $this->waitOnRead($sock); // to have it read and parsed...
                $data .= fread($sock, 1024);
            } while (!feof($sock));
            $this->assertSocket([
                [Rfc6455Endpoint::OP_TEXT, "foo".str_repeat("*", 65528)],
                [Rfc6455Endpoint::OP_TEXT, "bar"],
                [Rfc6455Endpoint::OP_PONG, "pingpong"],
                [Rfc6455Endpoint::OP_TEXT, "baz"],
                [Rfc6455Endpoint::OP_CLOSE],
            ], $data);

            \Amp\stop();
        });
        \Amp\reactor(\Amp\driver());
    }

    function testFragmentation() {
        \Amp\run(function () {
            list($endpoint, $client, $sock, $server) = yield from $this->initEndpoint(new NullWebsocket);
            $endpoint->sendBinary(null, str_repeat("*", 131064))->when(function() use ($sock, $server) { stream_socket_shutdown($sock, STREAM_SHUT_WR); $server->allowKill = true; });
            $data = "";
            do {
                yield $this->waitOnRead($sock); // to have it read and parsed...
                $data .= $x = fread($sock, 8192);
            } while ($x != "" || !feof($sock));
            $this->assertSocket([[Rfc6455Endpoint::OP_BIN, str_repeat("*", 65532)], [Rfc6455Endpoint::OP_CONT, str_repeat("*", 65532)]], $data);

            \Amp\stop();
        });
        \Amp\reactor(\Amp\driver());
    }

    function testSinglePong() {
        \Amp\run(function () {
            list($endpoint, $client, $sock, $server) = yield from $this->initEndpoint(new NullWebsocket);
            $client->pingCount = 2;
            $endpoint->onParse([Rfc6455Endpoint::CONTROL, "1", Rfc6455Endpoint::OP_PONG], $client);
            $this->assertEquals(1, $client->pongCount);

            \Amp\stop();
        });
        \Amp\reactor(\Amp\driver());
    }
}