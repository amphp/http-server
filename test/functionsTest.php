<?php

namespace Aerys\Test;

use Aerys\Client;
use Aerys\Request;
use Aerys\StandardResponse;
use PHPUnit\Framework\TestCase;

class functionsTest extends TestCase {
    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid redirect URI
     */
    public function testBadRedirectUrl() {
        \Aerys\redirect(":");
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid redirect URI; "http" or "https" scheme required
     */
    public function testBadRedirectScheme() {
        \Aerys\redirect("ssl://foo");
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid redirect URI; Host redirect must not contain a query or fragment component
     */
    public function testBadRedirectPath() {
        \Aerys\redirect("http://localhost/?foo");
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid redirect code; code in the range 300..399 required
     */
    public function testBadRedirectCode() {
        \Aerys\redirect("http://localhost", 201);
    }

    public function testSuccessfulRedirect() {
        $action = \Aerys\redirect("http://localhost", 301);
        $req = new class extends Request {
            public function __construct() {
            }
            public function getUri(): string {
                return "/foo";
            }
        };
        $action($req, new StandardResponse((function () use (&$body) {
            $headers = yield;
            $this->assertEquals("http://localhost/foo", $headers["location"][0]);
            $this->assertEquals(301, $headers[":status"]);
            $body = yield === null;
        })(), new Client));
        $this->assertTrue($body);
    }
}
