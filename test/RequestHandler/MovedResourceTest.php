<?php

namespace Amp\Http\Server\Test\RequestHandler;

use Amp\Http\Server\Driver\Client;
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

        new MovedResourceHandler("");
    }

    public function testBadRedirectCode(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid status code; code in the range 300..399 required");

        new MovedResourceHandler("/new/path", Status::CREATED);
    }

    public function testSuccessfulRedirect(): void
    {
        $action = new MovedResourceHandler("/new/path", Status::MOVED_PERMANENTLY);
        $uri = Uri\Http::createFromString("http://test.local/foo");
        $request = new Request($this->createMock(Client::class), "GET", $uri);

        $response = $action->handleRequest($request);

        self::assertSame(Status::MOVED_PERMANENTLY, $response->getStatus());
        self::assertSame("/new/path", $response->getHeader("location"));
    }

    public function testRequestWithQuery(): void
    {
        $action = new MovedResourceHandler("/new/path", Status::MOVED_PERMANENTLY);
        $uri = Uri\Http::createFromString("http://test.local/foo?key=value");
        $request = new Request($this->createMock(Client::class), "GET", $uri);

        $response = $action->handleRequest($request);

        self::assertSame(Status::MOVED_PERMANENTLY, $response->getStatus());
        self::assertSame("/new/path?key=value", $response->getHeader("location"));
    }
}
