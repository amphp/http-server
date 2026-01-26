<?php

namespace Amp\Http\Server\Test\RequestHandler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Driver\Internal;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\MovedResourceHandler;
use Amp\Http\Status;
use Amp\PHPUnit\AsyncTestCase;
use League\Uri;

class MovedResourceTest extends AsyncTestCase
{
    public function testEmptyPath(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Empty path in provided URI");

        new MovedResourceHandler(Internal\createUriFromString(""));
    }

    public function testBadRedirectCode(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid status code; code in the range 300..399 required");

        new MovedResourceHandler(Internal\createUriFromString("/new/path"), Status::CREATED);
    }

    public function testSuccessfulRedirect(): \Generator
    {
        $action = new MovedResourceHandler(Internal\createUriFromString("/new/path"), Status::MOVED_PERMANENTLY);
        $uri = Internal\createUriFromString("http://test.local/foo");
        $request = new Request($this->createMock(Client::class), "GET", $uri);

        /** @var \Amp\Http\Server\Response $response */
        $response = yield $action->handleRequest($request);

        $this->assertSame(Status::MOVED_PERMANENTLY, $response->getStatus());
        $this->assertSame("/new/path", $response->getHeader("location"));
    }

    public function testRequestWithQuery(): \Generator
    {
        $action = new MovedResourceHandler(Internal\createUriFromString("/new/path"), Status::MOVED_PERMANENTLY);
        $uri = Internal\createUriFromString("http://test.local/foo?key=value");
        $request = new Request($this->createMock(Client::class), "GET", $uri);

        /** @var \Amp\Http\Server\Response $response */
        $response = yield $action->handleRequest($request);

        $this->assertSame(Status::MOVED_PERMANENTLY, $response->getStatus());
        $this->assertSame("/new/path?key=value", $response->getHeader("location"));
    }
}
