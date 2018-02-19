<?php

namespace Aerys\Test;

use Aerys\Request;
use League\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface as PsrUri;
use function Amp\Promise\wait;

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
        $action = \Aerys\redirect("https://localhost", 301);
        $request = new class extends Request {
            public function __construct() {
            }
            public function getUri(): PsrUri {
                return Uri\Http::createFromString("http://test.local/foo");
            }
        };

        /** @var \Aerys\Response $response */
        $response = wait($action->respond($request));

        $this->assertSame(301, $response->getStatus());
        $this->assertSame("https://localhost/foo", $response->getHeader("location"));
    }
}
