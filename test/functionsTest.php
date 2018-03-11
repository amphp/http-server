<?php

namespace Amp\Http\Server\Test;

use Amp\Http\Server\Request;
use Amp\Http\Status;
use League\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface as PsrUri;
use function Amp\Http\Server\redirect;
use function Amp\Promise\wait;

class functionsTest extends TestCase {
    /**
     * @expectedException \Error
     * @expectedExceptionMessage The submitted uri `:` contains an invalid scheme
     */
    public function testBadRedirectUrl() {
        redirect(":");
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage The submitted uri `ssl://foo` is invalid for the following scheme(s): `http, https`
     */
    public function testBadRedirectScheme() {
        redirect("ssl://foo");
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid redirect URI; Host redirect must not contain a query or fragment component
     */
    public function testBadRedirectPath() {
        redirect("http://localhost/?foo");
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid redirect code; code in the range 300..399 required
     */
    public function testBadRedirectCode() {
        redirect("http://localhost", Status::CREATED);
    }

    public function testSuccessfulAbsoluteRedirect() {
        $action = redirect("https://localhost", Status::MOVED_PERMANENTLY);
        $request = new class extends Request {
            public function __construct() {
            }
            public function getUri(): PsrUri {
                return Uri\Http::createFromString("http://test.local/foo");
            }
        };

        /** @var \Amp\Http\Server\Response $response */
        $response = wait($action->respond($request));

        $this->assertSame(Status::MOVED_PERMANENTLY, $response->getStatus());
        $this->assertSame("https://localhost/foo", $response->getHeader("location"));
    }

    public function testSuccessfulRelativeRedirect() {
        $action = redirect("/test", Status::TEMPORARY_REDIRECT);
        $request = new class extends Request {
            public function __construct() {
            }
            public function getUri(): PsrUri {
                return Uri\Http::createFromString("http://test.local/foo");
            }
        };

        /** @var \Amp\Http\Server\Response $response */
        $response = wait($action->respond($request));

        $this->assertSame(Status::TEMPORARY_REDIRECT, $response->getStatus());
        $this->assertSame("/test/foo", $response->getHeader("location"));
    }
}
