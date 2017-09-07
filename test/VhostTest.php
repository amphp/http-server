<?php

namespace Aerys\Test;

use Aerys\Client;
use Aerys\InternalRequest;
use Aerys\Vhost;
use Aerys\VhostContainer;
use PHPUnit\Framework\TestCase;

class VhostTest extends TestCase {
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
        ];
    }

    /**
     * @dataProvider provideVhostRequests
     */
    public function testVhostSelection($uriHost, $uriPort, $addr, $port, $expected) {
        $vhosts = new VhostContainer($this->createMock('Aerys\HttpDriver'));
        $localvhost = new Vhost("localhost", [["127.0.0.1", 80], ["::", 8080]], function () {}, [function () {yield;}]);
        $vhosts->use($localvhost);

        $vhost = new Vhost("*", [["127.0.0.1", 80], ["::", 80]], function () {}, [function () {yield;}]);
        $vhosts->use($vhost);

        $portvhost = new Vhost("localhost:*", [["127.0.0.1", 80], ["::", 80]], function () {}, [function () {yield;}]);
        $vhosts->use($portvhost);

        $concreteportvhost = new Vhost("localhost:4444", [["127.0.0.1", 80]], function () {}, [function () {yield;}]);
        $vhosts->use($concreteportvhost);

        $this->assertEquals(4, $vhosts->count());

        $ireq = new InternalRequest;
        $ireq->client = new Client;
        $ireq->client->serverAddr = $addr;
        $ireq->client->serverPort = $port;
        $ireq->uriHost = $uriHost;
        $ireq->uriPort = $uriPort;

        $this->assertEquals([
            "*" => $vhost,
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

        $vhosts = new VhostContainer($this->createMock('Aerys\HttpDriver'));
        $vhost = new Vhost("*", [["127.0.0.1", 80], ["::", 80]], function () {}, [function () {yield;}]);
        $vhosts->use($vhost);
        $vhost = new Vhost("localhost", [["127.0.0.1", 80]], function () {}, [function () {yield;}]);
        $vhost->setCrypto(["local_cert" => __DIR__."/server.pem"]);
        $vhosts->use($vhost);
    }

    public function testCryptoVhost() {
        $vhosts = new VhostContainer($this->createMock('Aerys\HttpDriver'));
        $vhost = new Vhost("*", [["127.0.0.1", 80], ["::", 80]], function () {}, []);
        $vhosts->use($vhost);
        $vhost = new Vhost("localhost", [["127.0.0.1", 80]], function () {}, []);
        $vhosts->use($vhost);
        $vhost = new Vhost("localhost", [["127.0.0.1", 8080]], function () {}, []);
        $vhost->setCrypto(["local_cert" => __DIR__."/server.pem"]);
        $vhosts->use($vhost);

        $this->assertTrue(isset($vhosts->getTlsBindingsByAddress()["tcp://127.0.0.1:8080"]));
        $this->assertEquals(["tcp://127.0.0.1:80", "tcp://[::]:80", "tcp://127.0.0.1:8080"], array_values($vhosts->getBindableAddresses()));
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage At least one interface must be passed, an empty interfaces array is not allowed
     */
    public function testNoInterfaces() {
        new Vhost("*", [], function () {}, []);
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid host port: 0; integer in the range [1-65535] required
     */
    public function testBadPort() {
        new Vhost("*", [["127.0.0.1", 0]], function () {}, []);
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage IPv4 or IPv6 address required: rd.lo.wr.ey
     */
    public function testBadInterface() {
        new Vhost("*", [["rd.lo.wr.ey", 1025]], function () {}, []);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot have two hosts with the same name (localhost) on the same interface (127.0.0.1:80)
     */
    public function testMultipleSameHosts() {
        $vhosts = new VhostContainer($this->createMock('Aerys\HttpDriver'));
        $vhost = new Vhost("localhost", [["::", 80], ["127.0.0.1", 80]], function () {}, []);
        $vhosts->use($vhost);
        $vhost = new Vhost("localhost", [["127.0.0.1", 80]], function () {}, []);
        $vhosts->use($vhost);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot have two default hosts on the same interface (127.0.0.1:80)
     */
    public function testMultipleSameDefaultHostsOnSameInterface() {
        $vhosts = new VhostContainer($this->createMock('Aerys\HttpDriver'));
        $vhost = new Vhost("*", [["::", 80], ["127.0.0.1", 80]], function () {}, []);
        $vhosts->use($vhost);
        $vhost = new Vhost("*", [["127.0.0.1", 80]], function () {}, []);
        $vhosts->use($vhost);
    }
}
