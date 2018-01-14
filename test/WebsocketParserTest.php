<?php

namespace Aerys\Test;

use Aerys\HttpStatus;
use Aerys\Internal;
use Aerys\Internal\Client;
use Aerys\Internal\HttpDriver;
use Aerys\Options;
use Aerys\Request;
use Aerys\Response;
use Aerys\Server;
use Aerys\Websocket\Code;
use Aerys\Websocket\Endpoint;
use Aerys\Websocket\Internal\Rfc6455Client;
use Aerys\Websocket\Internal\Rfc6455Gateway;
use Aerys\Websocket\Message;
use Aerys\Websocket\Websocket;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Socket\Socket;
use Amp\Uri\Uri;
use Psr\Log\LoggerInterface as PsrLogger;

class WebsocketParserTest extends TestCase {
    public static function compile($opcode, $fin, $msg = "", $rsv = 0b000) {
        $len = strlen($msg);

        // FRRROOOO per RFC 6455 Section 5.2
        $w = chr(($fin << 7) | ($rsv << 4) | $opcode);

        // length as bits 2-2/4/6, with masking bit set
        if ($len > 0xFFFF) {
            $w .= "\xFF" . pack('J', $len);
        } elseif ($len > 0x7D) {
            $w .= "\xFE" . pack('n', $len);
        } else {
            $w .= chr($len | 0x80);
        }

        // 4 bit mask (random)
        $mask = "\xF4\x37\x7A\x9C";
        // apply mask
        $masked = $msg ^ str_repeat($mask, ($len + 3) >> 2);

        return $w . $mask . $masked;
    }

    /**
     * @dataProvider provideParserData
     */
    public function testParser($msg, array $message = null, array $error = null) {
        if ($message) {
            $websocket = new class($this, ...$message) implements Websocket {
                private $test;
                private $data;
                private $binary;

                public function __construct(TestCase $test, string $data, bool $isBinary) {
                    $this->test = $test;
                    $this->data = $data;
                    $this->binary = $isBinary;
                }

                public function onStart(Endpoint $endpoint) {
                }

                public function onHandshake(Request $request) {
                }

                public function onOpen(int $clientId, Request $request) {
                }

                public function onData(int $clientId, Message $msg) {
                    $this->test->assertSame($this->data, yield $msg);
                    $this->test->assertSame($this->binary, $msg->isBinary());
                }

                public function onClose(int $clientId, int $code, string $reason) {
                    // TODO: Implement onClose() method.
                }

                public function onStop() {
                    // TODO: Implement onStop() method.
                }
            };
        } else {
            $websocket = $this->createMock(Websocket::class);
        }

        $gateway = new Rfc6455Gateway($websocket);

        $client = new Rfc6455Client;
        $client->id = 1;
        $client->socket = $this->createMock(Socket::class);
        $parser = $gateway->parser($client, ['validate_utf8' => true]);

        $parser->send($msg);

        $this->assertSame($error[0] ?? null, $client->closeReason);
        $this->assertSame($error[1] ?? null, $client->closeCode);
    }

    public function provideParserData() {
        $return = [];

        // 0-13 -- basic text and binary frames with fixed lengths -------------------------------->

        foreach ([0 /* 0-1 */, 125 /* 2-3 */, 126 /* 4-5 */, 127 /* 6-7 */, 128 /* 8-9 */, 65535 /* 10-11 */, 65536 /* 12-13 */] as $length) {
            $data = str_repeat("*", $length);
            foreach ([Rfc6455Gateway::OP_TEXT, Rfc6455Gateway::OP_BIN] as $optype) {
                $input = $this->compile($optype, true, $data);
                $return[] = [$input, [$data, $optype]];
            }
        }
        //
        // 14-17 - basic control frame parsing ---------------------------------------------------->

        foreach (["" /* 14 */, "Hello world!" /* 15 */, "\x00\xff\xfe\xfd\xfc\xfb\x00\xff" /* 16 */, str_repeat("*", 125) /* 17 */] as $data) {
            $input = $this->compile(Rfc6455Gateway::OP_PING, true, $data);
            $return[] = [$input];
        }

        // 18 ---- error conditions: using a non-terminated frame with a control opcode ----------->

        $input = $this->compile(Rfc6455Gateway::OP_PING, false);
        $return[] = [$input, null, ["Illegal control frame fragmentation", Code::PROTOCOL_ERROR]];

        // 19 ---- error conditions: using a standalone continuation frame with fin = true -------->

        $input = $this->compile(Rfc6455Gateway::OP_CONT, true);
        $return[] = [$input, null, ["Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY", Code::PROTOCOL_ERROR]];

        // 20 ---- error conditions: using a standalone continuation frame with fin = false ------->

        $input = $this->compile(Rfc6455Gateway::OP_CONT, false);
        $return[] = [$input, null, ["Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY", Code::PROTOCOL_ERROR]];

        // 21 ---- error conditions: using a continuation frame after a finished text frame ------->

        $input = $this->compile(Rfc6455Gateway::OP_TEXT, true, "Hello, world!") . $this->compile(Rfc6455Gateway::OP_CONT, true);
        $return[] = [$input, ["Hello, world!", Rfc6455Gateway::OP_TEXT], ["Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY", Code::PROTOCOL_ERROR]];

        // 22-29 - continuation frame parsing ----------------------------------------------------->

        foreach ([[1, 0] /* 22-23 */, [126, 125] /* 24-25 */, [32767, 32769] /* 26-27 */, [32768, 32769] /* 28-29 */] as list($len1, $len2)) {
            // simple
            $input = $this->compile(Rfc6455Gateway::OP_TEXT, false, str_repeat("*", $len1)) . $this->compile(Rfc6455Gateway::OP_CONT, true, str_repeat("*", $len2));
            $return[] = [$input, [str_repeat("*", $len1 + $len2), Rfc6455Gateway::OP_TEXT]];

            // with interleaved control frame
            $input = $this->compile(Rfc6455Gateway::OP_TEXT, false, str_repeat("*", $len1)) . $this->compile(Rfc6455Gateway::OP_PING, true, "foo") . $this->compile(Rfc6455Gateway::OP_CONT, true, str_repeat("*", $len2));
            $return[] = [$input, [str_repeat("*", $len1 + $len2), Rfc6455Gateway::OP_TEXT]];
        }

        // 30 ---- error conditions: using a text frame after a not finished text frame ----------->

        $input = $this->compile(Rfc6455Gateway::OP_TEXT, false, "Hello, world!") . $this->compile(Rfc6455Gateway::OP_TEXT, true, "uhm, no!");
        $return[] = [$input, null, ["Illegal data type opcode after unfinished previous data type frame; opcode MUST be CONTINUATION", Code::PROTOCOL_ERROR]];

        // 31 ---- utf-8 validation must resolve for large utf-8 msgs ----------------------------->

        $data = "H".str_repeat("ö", 32770);
        $input = $this->compile(Rfc6455Gateway::OP_TEXT, false, substr($data, 0, 32769)) . $this->compile(Rfc6455Gateway::OP_CONT, true, substr($data, 32769));
        $return[] = [$input, [$data, Rfc6455Gateway::OP_TEXT]];

        // 32 ---- utf-8 validation must resolve for interrupted utf-8 across frame boundary ------>

        $data = "H".str_repeat("ö", 32770);
        $input = $this->compile(Rfc6455Gateway::OP_TEXT, false, substr($data, 0, 32768)) . $this->compile(Rfc6455Gateway::OP_CONT, true, substr($data, 32768));
        $return[] = [$input, [$data, Rfc6455Gateway::OP_TEXT]];

        // 33 ---- utf-8 validation must fail for bad utf-8 data (single frame) ------------------->

        $input = $this->compile(Rfc6455Gateway::OP_TEXT, true, substr(str_repeat("ö", 2), 1));
        $return[] = [$input, null, ["Invalid TEXT data; UTF-8 required", Code::INCONSISTENT_FRAME_DATA_TYPE]];

        // 34 ---- utf-8 validation must fail for bad utf-8 data (multiple small frames) ---------->

        $data = "H".str_repeat("ö", 3);
        $input = $this->compile(Rfc6455Gateway::OP_TEXT, false, substr($data, 0, 2)) . $this->compile(Rfc6455Gateway::OP_CONT, true, substr($data, 3));
        $return[] = [$input, null, ["Invalid TEXT data; UTF-8 required", Code::INCONSISTENT_FRAME_DATA_TYPE]];

        // 35 ---- utf-8 validation must fail for bad utf-8 data (multiple big frames) ------------>

        $data = "H".str_repeat("ö", 40000);
        $input = $this->compile(Rfc6455Gateway::OP_TEXT, false, substr($data, 0, 32767)) . $this->compile(Rfc6455Gateway::OP_CONT, false, substr($data, 32768));
        $return[] = [$input, null, ["Invalid TEXT data; UTF-8 required", Code::INCONSISTENT_FRAME_DATA_TYPE]];

        // 36 ---- error conditions: using a too large payload with a control opcode -------------->

        $input = $this->compile(Rfc6455Gateway::OP_PING, true, str_repeat("*", 126));
        $return[] = [$input, null, ["Control frame payload must be of maximum 125 bytes or less", Code::PROTOCOL_ERROR]];

        // 37 ---- error conditions: unmasked data ------------------------------------------------>

        $input = substr($this->compile(Rfc6455Gateway::OP_PING, true, str_repeat("*", 125)), 0, -4) & ("\xFF\x7F" . str_repeat("\xFF", 0xFF));
        $return[] = [$input, null, ["Payload mask required", Code::PROTOCOL_ERROR]];

        // 38 ---- error conditions: too large frame (> 2^63 bit) --------------------------------->

        $input = $this->compile(Rfc6455Gateway::OP_BIN, true, str_repeat("*", 65536)) | ("\x00\x00\x80" . str_repeat("\x00", 0xFF));
        $return[] = [$input, null, ["Most significant bit of 64-bit length field set", Code::PROTOCOL_ERROR]];


        // 39 ---- utf-8 must be accepted for interrupted text with interleaved control frame ----->

        $data = "H".str_repeat("ö", 32770);
        $input = $this->compile(Rfc6455Gateway::OP_TEXT, false, substr($data, 0, 32768)) . $this->compile(Rfc6455Gateway::OP_PING, true, "foo") . $this->compile(Rfc6455Gateway::OP_CONT, true, substr($data, 32768));
        $return[] = [$input, [$data, Rfc6455Gateway::OP_TEXT]];

        // x -------------------------------------------------------------------------------------->

        return $return;
    }

    public function testUpgrading() {
        Loop::run(function () use (&$unloaded) {
            $client = new Client;
            $client->id = 1;
            $client->readWatcher = 'a';
            $client->writeWatcher = 'b';
            list($sock, $client->socket) = stream_socket_pair(\stripos(PHP_OS, "win") === 0 ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            $driver = new class($this, $client) implements HttpDriver {
                private $test;
                private $emit;
                public $response;
                public $body;
                private $client;

                public function __construct($test, $client) {
                    $this->test = $test;
                    $this->client = $client;
                    $this->client->serverPort = 80;
                    $this->client->httpDriver = $this;
                }

                public function setup(array $parseEmitters, callable $write) {
                    $this->emit = $parseEmitters[HttpDriver::RESULT];
                }

                public function writer(Internal\ServerRequest $ireq, Response $response): \Generator {
                    $this->response = $response;
                    $this->body = "";
                    do {
                        $this->body .= $part = yield;
                    } while ($part !== null);
                }

                public function parser(Client $client): \Generator {
                    $this->test->fail("We shouldn't be invoked the parser with no actual clients");
                }

                public function emit() {
                    $ireq = new Internal\ServerRequest;
                    $ireq->client = $this->client;
                    $ireq->protocol = "1.1";
                    $ireq->method = "GET";
                    $ireq->uri = new Uri("http://localhost:80/foo");
                    $ireq->headers = ["host" => ["localhost"], "sec-websocket-key" => ["x3JJHMbDL1EzLkh9GBhXDw=="], "sec-websocket-version" => ["13"], "upgrade" => ["websocket"], "connection" => ["keep-alive, upgrade"]];
                    $ireq->trace = [["host", "localhost"], /* irrelevant ... */];
                    ($this->emit)($ireq);
                }

                public function upgradeBodySize(Internal\ServerRequest $ireq, int $bodySize) {
                }
            };

            $logger = $this->createMock(PsrLogger::class);

            $ws = $this->createMock(Websocket::class);
            $ws->expects($this->exactly(1))
                ->method("onHandshake")
                ->will($this->returnValue((function () { if (0) { yield; } return "foo"; })()));
            $ws->expects($this->exactly(1))
                ->method("onOpen")
                ->willReturnCallback(function (int $clientId, $handshakeData) {
                    $this->assertEquals("foo", $handshakeData);
                });
            $ws = \Aerys\websocket($ws);

            $options = new Options;
            $options->debug = true;

            $server = new Server($options, $logger, $driver);
            $server->use($ws);
            $server->expose("::", 9001);
            yield $server->start();

            $driver->emit();

            yield $server->stop();

            /** @var \Aerys\Response $response */
            $response = $driver->response;
            $this->assertSame(HttpStatus::SWITCHING_PROTOCOLS, $response->getStatus());
            $this->assertSame("websocket", $response->getHeader("upgrade"));
            $this->assertSame("upgrade", $response->getHeader("connection"));
            $this->assertSame("HSmrc0sMlYUkAGmm5OPpG2HaGWk=", $response->getHeader("sec-websocket-accept"));
            $this->assertSame("", $driver->body);

            // we need to test for unloading here (and not against unloading), because otherwise destructor order is not deterministic and it may spuriously succeed or fail
            fclose($sock);
            Loop::defer([Loop::class, "stop"]);
            $this->assertTrue($client->isExported);
        });
    }
}
