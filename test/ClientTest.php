<?php

namespace Aerys\Test;

use Aerys\CallableResponder;
use Aerys\Logger;
use Aerys\Middleware;
use Aerys\Options;
use Aerys\Request;
use Aerys\Responder;
use Aerys\Response;
use Aerys\Server;
use Amp\Artax\DefaultClient;
use Amp\ByteStream\InMemoryStream;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket;
use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ServerTlsContext;
use PHPUnit\Framework\TestCase;
use function Amp\call;

class ClientTest extends TestCase {
    public function startServer(callable $handler, array $middlewares = []) {
        if (!$server = @stream_socket_server("tcp://127.0.0.1:*", $errno, $errstr)) {
            $this->markTestSkipped("Couldn't get a free port from the local ephemeral port range");
        }
        $address = stream_socket_get_name($server, $wantPeer = false);
        fclose($server);
        $port = parse_url($address, PHP_URL_PORT);

        $handler = new CallableResponder($handler);

        $logger = $this->createMock(Logger::class);
        $options = new Options;
        $options->debug = true;
        $server = new Server($handler, $options, $logger);
        $server->expose("*", $port);
        $server->encrypt((new ServerTlsContext)->withDefaultCertificate(new Certificate(__DIR__."/server.pem")));

        yield $server->start();
        return [$address, $server];
    }

    public function testTrivialHttpRequest() {
        Loop::run(function () {
            list($address, $server) = yield from $this->startServer(function (Request $req) {
                $this->assertEquals("GET", $req->getMethod());
                $this->assertEquals("/uri", $req->getUri()->getPath());
                $this->assertEquals(["foo" => ["bar"], "baz" => ["1", "2"]], $req->getUri()->getAllQueryParameters());
                $this->assertEquals(["header"], $req->getHeaderArray("custom"));
                $this->assertEquals("value", $req->getCookie("test"));

                $data = \str_repeat("*", 100000);
                $stream = new InMemoryStream("data/" . $data . "/data");

                $res = new Response($stream);

                $res->setCookie("cookie", "with-value");
                $res->setHeader("custom", "header");

                return $res;
            });

            $cookies = new \Amp\Artax\Cookie\ArrayCookieJar;
            $cookies->store(new \Amp\Artax\Cookie\Cookie("test", "value", null, "/", "localhost"));
            $context = (new ClientTlsContext)->withoutPeerVerification();
            $client = new DefaultClient($cookies, null, $context);
            $port = parse_url($address, PHP_URL_PORT);
            $promise = $client->request(
                (new \Amp\Artax\Request("https://localhost:$port/uri?foo=bar&baz=1&baz=2", "GET"))->withHeader("custom", "header")
            );

            $res = yield $promise;
            $this->assertEquals(200, $res->getStatus());
            $this->assertEquals(["header"], $res->getHeaderArray("custom"));
            $body = yield $res->getBody();
            $this->assertEquals("data/" . str_repeat("*", 100000) . "/data", $body);
            $this->assertEquals("with-value", $cookies->get("localhost", "/", "cookie")[0]->getValue());

            Loop::stop();
        });
    }

    public function testClientDisconnect() {
        Loop::run(function () {
            list($address, $server) = yield from $this->startServer(function (Request $req) use (&$server) {
                $this->assertEquals("POST", $req->getMethod());
                $this->assertEquals("/", $req->getUri()->getPath());
                $this->assertEquals([], $req->getAllParams());
                $this->assertEquals("body", yield $req->getBody()->buffer());

                $data = "data";
                $data .= \str_repeat("_", $server->getOptions()->getOutputBufferSize() + 1);

                return new Response(new InMemoryStream($data));
            }, [new class($this) implements Middleware {
                private $test;
                public function __construct(TestCase $test) {
                    $this->test = $test;
                }
                public function process(Request $request, Responder $responder): Promise {
                    return call(function () use ($request, $responder) {
                        $this->test->assertSame("1.0", $request->getProtocolVersion());
                        return $responder->respond($request);
                    });
                }
            }]);

            $port = parse_url($address, PHP_URL_PORT);
            $context = (new ClientTlsContext)->withoutPeerVerification();
            $socket = yield Socket\cryptoConnect("tcp://localhost:$port/", null, $context);

            $request = "POST / HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\nContent-Length: 4\r\n\r\nbody";
            yield $socket->write($request);

            $socket->close();

            Loop::delay(100, function () use ($socket) {
                Loop::stop();
            });
        });
    }
}
