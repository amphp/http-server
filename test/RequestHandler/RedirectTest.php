<?php

namespace Amp\Http\Server\Test\RequestHandler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\RedirectHandler;
use Amp\Http\Status;
use Amp\PHPUnit\AsyncTestCase;
use League\Uri;
use function Amp\Http\Server\redirectTo;

class RedirectTest extends AsyncTestCase
{
    public function testBadRedirectPath(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid redirect URI; Host redirect must not contain a query or fragment component");

        new RedirectHandler(Uri\Http::createFromString("http://localhost/?foo"));
    }

    public function testBadRedirectCode(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid status code; code in the range 300..399 required");

        new RedirectHandler(Uri\Http::createFromString("http://localhost"), Status::CREATED);
    }

    public function testSuccessfulAbsoluteRedirect(): \Generator
    {
        $action = new RedirectHandler(Uri\Http::createFromString("https://localhost"), Status::MOVED_PERMANENTLY);
        $uri = Uri\Http::createFromString("http://test.local/foo");
        $request = new Request($this->createMock(Client::class), "GET", $uri);

        /** @var \Amp\Http\Server\Response $response */
        $response = yield $action->handleRequest($request);

        $this->assertSame(Status::MOVED_PERMANENTLY, $response->getStatus());
        $this->assertSame("https://localhost/foo", $response->getHeader("location"));
    }

    public function testSuccessfulRelativeRedirect(): \Generator
    {
        $action = new RedirectHandler(Uri\Http::createFromString("/test"));
        $uri = Uri\Http::createFromString("http://test.local/foo");
        $request = new Request($this->createMock(Client::class), "GET", $uri);

        /** @var \Amp\Http\Server\Response $response */
        $response = yield $action->handleRequest($request);

        $this->assertSame(Status::TEMPORARY_REDIRECT, $response->getStatus());
        $this->assertSame("/test/foo", $response->getHeader("location"));
    }

    public function testRedirectWithQuery(): \Generator
    {
        $action = new RedirectHandler(Uri\Http::createFromString("/new/path"), Status::MOVED_PERMANENTLY);
        $uri = Uri\Http::createFromString("http://test.local/foo?key=value");
        $request = new Request($this->createMock(Client::class), "GET", $uri);

        /** @var \Amp\Http\Server\Response $response */
        $response = yield $action->handleRequest($request);

        $this->assertSame(Status::MOVED_PERMANENTLY, $response->getStatus());
        $this->assertSame("/new/path/foo?key=value", $response->getHeader("location"));
    }

    public function testRedirectTo(): void
    {
        $response = redirectTo('/foobar', Status::PERMANENT_REDIRECT);

        $this->assertSame(Status::PERMANENT_REDIRECT, $response->getStatus());
        $this->assertSame('/foobar', $response->getHeader('location'));
    }

    public function testRedirectTo_DefaultStatus(): void
    {
        $this->assertSame(302, redirectTo('/foobar')->getStatus());
    }
}
