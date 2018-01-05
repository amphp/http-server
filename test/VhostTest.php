<?php

namespace Aerys\Test;

use Aerys\Internal;
use Aerys\CallableResponder;
use Aerys\Internal\Vhost;
use Aerys\Internal\VhostContainer;
use Amp\Socket\Certificate;
use Amp\Socket\ServerTlsContext;
use Amp\Uri\Uri;
use PHPUnit\Framework\TestCase;

class VhostTest extends TestCase {
    private static function socketPath() {
        return realpath(sys_get_temp_dir()) . "/aerys_vhost_test_no_socket.sock";
    }

    public function provideVhostRequests() {
        return [
            0 => ["::1", 8080, "[::1]", 8080, null],
            1 => ["127.0.0.1", 80, "127.0.0.1", 80, "*"],
            2 => ["localhost", 80, "127.0.0.1", 80, "local"],
            3 => ["localhost", 80, "::", 80, "*"],
            4 => ["localhost", 8080, "::", 8080, "local"],
            5 => ["localhost", 8080, "::", 80, "local:*"],
            6 => ["localhost", 8080, "127.0.0.1", 80, "local:*"],
            7 => ["foobar", 8080, "127.0.0.1", 80, null],
            8 => ["localhost", 4444, "127.0.0.1", 80, "local:4444"],
            9 => ["foobar", 80, "127.0.0.1", 80, "*"],
            10 => ["localhost", 8080, "[::1]", 8080, "local"],
            11 => ["localhost", 80, self::socketPath(), 0, "unix:80"],
            12 => ["localhost", 0, self::socketPath(), 0, "unix:80"],
            13 => ["localhost", 8080, self::socketPath(), 0, "unix"],
            14 => ["localhost", 0, "/foo", 0, null],
        ];
    }

    /**
     * @dataProvider provideVhostRequests
     */
    public function testVhostSelection($uriHost, $uriPort, $addr, $port, $expected) {
        $responder = new CallableResponder(function () {});

        $vhosts = new VhostContainer($this->createMock(Internal\HttpDriver::class));
        $localvhost = new Vhost("localhost", [["127.0.0.1", 80], ["::", 8080]], $responder, []);
        $vhosts->use($localvhost);

        $vhost = new Vhost("*", [["127.0.0.1", 80], ["::", 80]], $responder, []);
        $vhosts->use($vhost);

        $portvhost = new Vhost("localhost:*", [["127.0.0.1", 80], ["::", 80]], $responder, []);
        $vhosts->use($portvhost);

        $concreteportvhost = new Vhost("localhost:4444", [["127.0.0.1", 80]], $responder, []);
        $vhosts->use($concreteportvhost);

        $unixvhost = new Vhost("localhost", [[self::socketPath(), 0]], $responder, []);
        $vhosts->use($unixvhost);

        $unixportvhost = new Vhost("*:80", [[self::socketPath(), 0]], $responder, []);
        $vhosts->use($unixportvhost);

        $this->assertSame(6, $vhosts->count());

        $ireq = new Internal\ServerRequest;
        $ireq->client = new Internal\Client;
        $ireq->client->serverAddr = $addr;
        $ireq->client->serverPort = $port;

        if ($uriPort) {
            $ireq->uri = new Uri(\sprintf("http://%s:%s", $uriHost, $uriPort));
        } else {
            $ireq->uri = new Uri("http://" . $uriHost);
        }

        $this->assertEquals([
            "*" => $vhost,
            "unix" => $unixvhost,
            "unix:80" => $unixportvhost,
            "local:*" => $portvhost,
            "local:4444" => $concreteportvhost,
            "local" => $localvhost
        ][$expected] ?? null, $vhosts->selectHost($ireq));
    }

    public function provideCryptoResolutionFailureCases() {
        return [
            0 => [[["127.0.0.1", 80], ["::", 80]], [["127.0.0.1", 80]], "Cannot register encrypted host `localhost`; unencrypted host `*` registered on conflicting interface `127.0.0.1:80`"],
            1 => [[["0.0.0.0", 80], ["::", 80]], [["127.0.0.1", 80]], "Cannot register encrypted host `localhost`; unencrypted host `*` registered on conflicting interface `127.0.0.1:80`"],
            2 => [[["127.0.0.1", 80], ["::", 80]], [["0.0.0.0", 80]], "Cannot register encrypted host `localhost`; unencrypted host `*` registered on conflicting interface `127.0.0.1:80`"],
        ];
    }

    /**
     * @dataProvider provideCryptoResolutionFailureCases
     * @expectedException \Error
     */
    public function testCryptoResolutionFailure($plaintextInterfaces, $encryptedInterfaces, $msg) {
        $this->expectExceptionMessage($msg);

        $vhosts = new VhostContainer($this->createMock(Internal\HttpDriver::class));
        $vhost = new Vhost("*", [["127.0.0.1", 80], ["::", 80]], new CallableResponder(function () {}), []);
        $vhosts->use($vhost);
        $vhost = new Vhost("localhost", [["127.0.0.1", 80]], new CallableResponder(function () {}), []);
        $vhost->setCrypto((new ServerTlsContext)->withDefaultCertificate(new Certificate(__DIR__."/server.pem")));
        $vhosts->use($vhost);
    }

    public function testCryptoVhost() {
        $vhosts = new VhostContainer($this->createMock(Internal\HttpDriver::class));
        $vhost = new Vhost("*", [["127.0.0.1", 80], ["::", 80]], new CallableResponder(function () {}), []);
        $vhosts->use($vhost);
        $vhost = new Vhost("localhost", [["127.0.0.1", 80]], new CallableResponder(function () {}), []);
        $vhosts->use($vhost);
        $vhost = new Vhost("localhost", [["127.0.0.1", 8080]], new CallableResponder(function () {}), []);
        $vhost->setCrypto((new ServerTlsContext)->withDefaultCertificate(new Certificate(__DIR__."/server.pem")));
        $vhosts->use($vhost);

        $this->assertTrue(isset($vhosts->getTlsBindingsByAddress()["tcp://127.0.0.1:8080"]));
        $this->assertEquals(["tcp://127.0.0.1:80", "tcp://[::]:80", "tcp://127.0.0.1:8080"], array_values($vhosts->getBindableAddresses()));
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage At least one interface must be passed, an empty interfaces array is not allowed
     */
    public function testNoInterfaces() {
        new Vhost("*", [], new CallableResponder(function () {}), []);
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid host port: 0; integer in the range [1-65535] required
     */
    public function testBadPort() {
        new Vhost("*", [["127.0.0.1", 0]], new CallableResponder(function () {}), []);
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage IPv4 or IPv6 address or unix domain socket path required: rd.lo.wr.ey
     */
    public function testBadInterface() {
        new Vhost("*", [["rd.lo.wr.ey", 1025]], new CallableResponder(function () {}), []);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot have two hosts with the same name (localhost) on the same interface (127.0.0.1:80)
     */
    public function testMultipleSameHosts() {
        $vhosts = new VhostContainer($this->createMock(Internal\HttpDriver::class));
        $vhost = new Vhost("localhost", [["::", 80], ["127.0.0.1", 80]], new CallableResponder(function () {}), []);
        $vhosts->use($vhost);
        $vhost = new Vhost("localhost", [["127.0.0.1", 80]], new CallableResponder(function () {}), []);
        $vhosts->use($vhost);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot have two default hosts on the same interface (127.0.0.1:80)
     */
    public function testMultipleSameDefaultHostsOnSameInterface() {
        $vhosts = new VhostContainer($this->createMock(Internal\HttpDriver::class));
        $vhost = new Vhost("*", [["::", 80], ["127.0.0.1", 80]], new CallableResponder(function () {}), []);
        $vhosts->use($vhost);
        $vhost = new Vhost("*", [["127.0.0.1", 80]], new CallableResponder(function () {}), []);
        $vhosts->use($vhost);
    }
}
