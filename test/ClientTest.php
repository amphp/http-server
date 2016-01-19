<?php

namespace Aerys\Test;

use Aerys\ClientException;
use Aerys\InternalRequest;
use Amp\Artax\Client;
use Aerys\Http1Driver;
use Aerys\Http2Driver;
use Aerys\Logger;
use Aerys\Options;
use Aerys\Request;
use Aerys\Response;
use Aerys\Server;
use Aerys\Ticker;
use Aerys\Vhost;
use Aerys\VhostContainer;
use Amp\Artax\Notify;
use Amp\Artax\SocketException;
use Amp\Socket as sock;

class ClientTest extends \PHPUnit_Framework_TestCase {
    function startServer($handler, $filters = []) {
        if (!$server = @stream_socket_server("tcp://127.0.0.1:*", $errno, $errstr)) {
            $this->markTestSkipped("Couldn't get a free port from the local ephemeral port range");
        }
        $address = stream_socket_get_name($server, $wantPeer = false);
        fclose($server);
        $port = parse_url($address, PHP_URL_PORT);

        $vhosts = new VhostContainer(new Http2Driver);
        $vhost = new Vhost("localhost", [["127.0.0.1", $port]], $handler, $filters, new Http1Driver);
        $vhost->setCrypto(["local_cert" => __DIR__."/server.pem", "crypto_method" => "tls"]);
        $vhosts->use($vhost);

        $logger = new class extends Logger { protected function output(string $message) { /* /dev/null */ } };
        $server = new Server(new Options, $vhosts, $logger, new Ticker($logger));
        yield $server->start();
        return [$address, $server];
    }

    function testClientDisconnect() {
        \Amp\run(function() {
            $deferred = new \Amp\Deferred;
            list($address, $server) = yield from $this->startServer(function (Request $req, Response $res) use ($deferred, &$server) {
                $this->assertEquals("POST", $req->getMethod());
                $this->assertEquals("/", $req->getUri());

                try {
                    $res->stream("data");
                    yield $res->flush();
                    yield $res->stream(str_repeat("_", $server->getOption("outputBufferSize") + 1));
                    $this->fail("Should not be reached");
                } catch (ClientException $e) {
                    $deferred->succeed();
                } catch (\Throwable $e) {
                    $deferred->fail($e);
                }
            }, [function(InternalRequest $res) {
                $headers = yield;

                $data = yield $headers;
                $this->assertEquals("data", $data);

                $flush = yield $data;
                $this->assertFalse($flush);

                do { $end = yield; } while ($end === false);
                $this->assertEquals("_", $end[0]);

                // now shut the socket down (fake disconnect)
                fclose($res->client->socket);

                return $end;
            }]);

            $client = new Client;
            $client->setOption(Client::OP_CRYPTO, ["allow_self_signed" => true, "peer_name" => "localhost", "crypto_method" => STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT]);
            $promise = $client->request((new \Amp\Artax\Request)
                ->setUri("https://$address/")
                ->setMethod("POST")
            );

            $body = "";
            $promise->watch(function($update) use (&$body) {
                list($type, $data) = $update;
                if ($type == Notify::RESPONSE_BODY_DATA) {
                    $body .= $data;
                }
            });
            try {
                yield $promise;
            } catch (SocketException $e) { }
            $this->assertTrue(isset($e));
            $this->assertEquals("data", $body);

            yield $deferred->promise();
            \Amp\stop();
            \Amp\reactor(\Amp\driver());
        });
    }
}
