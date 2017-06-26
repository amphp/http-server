<?php

namespace Aerys\Test;

use Aerys\Client;
use Aerys\Host;
use Aerys\Http1Driver;
use Aerys\Http2Driver;
use Aerys\StandardRequest;
use Aerys\StandardResponse;
use PHPUnit\Framework\TestCase;

class HostTest extends TestCase {
    public function getHost() { // we do not want to add to definitions, that's for the Bootstrapper test.
        return (new \ReflectionClass('Aerys\Host'))->newInstanceWithoutConstructor();
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid port number; integer in the range 1..65535 required
     */
    public function testThrowsWithBadPort() {
        $this->getHost()->expose("127.0.0.1", 65536);
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid IP address
     */
    public function testThrowsWithBadInterface() {
        $this->getHost()->expose("bizzibuzzi", 1025);
    }

    public function testGenericInterface() {
        $host = $this->getHost();
        $host->expose("*", 1025);
        if (Host::separateIPv4Binding()) {
            $this->assertEquals([["0.0.0.0", 1025], ["::", 1025]], $host->export()["interfaces"]);
        } else {
            $this->assertEquals([["::", 1025]], $host->export()["interfaces"]);
        }
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Aerys\Host::use requires a callable action or Bootable or Middleware or HttpDriver instance
     */
    public function testBadUse() {
        $this->getHost()->use(1);
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid redirect URI
     */
    public function testBadRedirectUrl() {
        $this->getHost()->redirect(":");
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid redirect URI; "http" or "https" scheme required
     */
    public function testBadRedirectScheme() {
        $this->getHost()->redirect("ssl://foo");
    }


    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid redirect URI; Host redirect must not contain a query or fragment component
     */
    public function testBadRedirectPath() {
        $this->getHost()->redirect("http://localhost/?foo");
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid redirect code; code in the range 300..399 required
     */
    public function testBadRedirectCode() {
        $this->getHost()->redirect("http://localhost", 201);
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Impossible to define two HttpDriver instances for one same Host; an instance of Aerys\Http1Driver has already been defined as driver
     */
    public function testDriverRedefine() {
        $this->getHost()->use(new Http1Driver)->use(new Http2Driver);
    }


    public function testSuccessfulRedirect() {
        $actions = $this->getHost()->redirect("http://localhost", 301)->export()["actions"];
        $this->assertEquals(1, count($actions));
        $req = new class extends StandardRequest {
            public function __construct() {
            }
            public function getUri(): string {
                return "/foo";
            }
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
