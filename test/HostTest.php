<?php

namespace Aerys\Test;

use Aerys\Client;
use Aerys\Host;
use Aerys\Http1Driver;
use Aerys\Http2Driver;
use Aerys\StandardRequest;
use Aerys\StandardResponse;

class HostTest extends \PHPUnit_Framework_TestCase {
    function getHost() { // we do not want to add to definitions, that's for the Bootstrapper test.
        return (new \ReflectionClass('Aerys\Host'))->newInstanceWithoutConstructor();
    }

    /**
     * @expectedException \DomainException
     * @expectedExceptionMessage Invalid port number; integer in the range 1..65535 required
     */
    function testThrowsWithBadPort() {
        $this->getHost()->expose("127.0.0.1", 65536);
    }

    /**
     * @expectedException \DomainException
     * @expectedExceptionMessage Invalid IP address
     */
    function testThrowsWithBadInterface() {
        $this->getHost()->expose("bizzibuzzi", 1025);
    }

    function testGenericInterface() {
        $host = $this->getHost();
        $host->expose("*", 1025);
        if (Host::separateIPv4Binding()) {
            $this->assertEquals([["0.0.0.0", 1025], ["::", 1025]], $host->export()["interfaces"]);
        } else {
            $this->assertEquals([["::", 1025]], $host->export()["interfaces"]);
        }
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Aerys\Host::use requires a callable action or Bootable or Middleware or HttpDriver instance
     */
    function testBadUse() {
        $this->getHost()->use(1);
    }

    /**
     * @expectedException \DomainException
     * @expectedExceptionMessage Invalid redirect URI
     */
    function testBadRedirectUrl() {
        $this->getHost()->redirect(":");
    }

    /**
     * @expectedException \DomainException
     * @expectedExceptionMessage Invalid redirect URI; "http" or "https" scheme required
     */
    function testBadRedirectScheme() {
        $this->getHost()->redirect("ssl://foo");
    }


    /**
     * @expectedException \DomainException
     * @expectedExceptionMessage Invalid redirect URI; Host redirect must not contain a path component
     */
    function testBadRedirectPath() {
        $this->getHost()->redirect("http://localhost/path");
    }

    /**
     * @expectedException \DomainException
     * @expectedExceptionMessage Invalid redirect code; code in the range 300..399 required
     */
    function testBadRedirectCode() {
        $this->getHost()->redirect("http://localhost", 201);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Impossible to define two HttpDriver instances for one same Host; an instance of Aerys\Http1Driver has already been defined as driver
     */
    function testDriverRedefine() {
        $this->getHost()->use(new Http1Driver)->use(new Http2Driver);
    }


    function testSuccessfulRedirect() {
        $actions = $this->getHost()->redirect("http://localhost", 301)->export()["actions"];
        $this->assertEquals(1, count($actions));
        $req = new class extends StandardRequest {
            public function __construct() { }
            public function getUri(): string { return "/foo"; }
        };
        $actions[0]($req, new StandardResponse((function () use (&$body) {
            $headers = yield;
            $this->assertEquals("http://localhost/foo", $headers["location"][0]);
            $this->assertEquals(301, $headers[":status"]);
            $body = yield === null;
        })(), new Client));
        $this->assertTrue($body);
    }
}