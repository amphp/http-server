<?php

namespace Aerys\Test;

use Aerys\Client;
use Aerys\InternalRequest;
use Aerys\Vhost;
use Aerys\VhostContainer;
use PHPUnit\Framework\TestCase;

class VhostTest extends TestCase {
    public function testVhostSelection() {
        $vhosts = new VhostContainer($this->createMock('Aerys\HttpDriver'));
        $localvhost = new Vhost("localhost", [["127.0.0.1", 80], ["::", 8080]], function () {}, [function () {yield;}]);
        $vhosts->use($localvhost);
        $vhost = new Vhost("", [["127.0.0.1", 80], ["::", 80]], function () {}, [function () {yield;}]);
        $vhosts->use($vhost);

        $this->assertEquals(2, $vhosts->count());

        $ireq = new InternalRequest;
        $ireq->client = new Client;
        $ireq->uriHost = "[::1]";
        $ireq->uriPort = 8080;
        $this->assertEquals(null, $vhosts->selectHost($ireq));
        $ireq->uriHost = "127.0.0.1";
        $ireq->uriPort = 80;
        $this->assertEquals($vhost, $vhosts->selectHost($ireq));

        $ireq->uriPort = 80;
        $ireq->uriHost = "localhost";
        $this->assertEquals($localvhost, $vhosts->selectHost($ireq));
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Cannot register encrypted host `localhost`; unencrypted host `*` registered on conflicting port `127.0.0.1:80`
     */
    public function testCryptoResolutionFailure() {
        $vhosts = new VhostContainer($this->createMock('Aerys\HttpDriver'));
        $vhost = new Vhost("", [["127.0.0.1", 80], ["::", 80]], function () {}, [function () {yield;}]);
        $vhosts->use($vhost);
        $vhost = new Vhost("localhost", [["127.0.0.1", 80]], function () {}, [function () {yield;}]);
        $vhost->setCrypto(["local_cert" => __DIR__."/server.pem"]);
        $vhosts->use($vhost);
    }

    public function testCryptoVhost() {
        $vhosts = new VhostContainer($this->createMock('Aerys\HttpDriver'));
        $vhost = new Vhost("", [["127.0.0.1", 80], ["::", 80]], function () {}, []);
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
        new Vhost("", [], function () {}, []);
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid host port: 0; integer in the range [1-65535] required
     */
    public function testBadPort() {
        new Vhost("", [["127.0.0.1", 0]], function () {}, []);
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage IPv4 or IPv6 address required: rd.lo.wr.ey
     */
    public function testBadInterface() {
        new Vhost("", [["rd.lo.wr.ey", 1025]], function () {}, []);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot have two hosts with the same `localhost:80` name
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
     * @expectedExceptionMessage Cannot have two default hosts on the same `127.0.0.1:80` interface
     */
    public function testMultipleSameDefaultHostsOnSameInterface() {
        $vhosts = new VhostContainer($this->createMock('Aerys\HttpDriver'));
        $vhost = new Vhost("", [["::", 80], ["127.0.0.1", 80]], function () {}, []);
        $vhosts->use($vhost);
        $vhost = new Vhost("", [["127.0.0.1", 80]], function () {}, []);
        $vhosts->use($vhost);
    }
}
