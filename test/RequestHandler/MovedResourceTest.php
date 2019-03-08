<?php

namespace Amp\Http\Server\Test\RequestHandler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\MovedResourceHandler;
use Amp\Http\Status;
use League\Uri;
use PHPUnit\Framework\TestCase;
use function Amp\Promise\wait;

class MovedResourceTest extends TestCase
{
    /**
     * @expectedException \Error
     * @expectedExceptionMessage Empty path in provided URI
     */
    public function testEmptyPath()
    {
        new MovedResourceHandler(Uri\Http::createFromString(""));
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid status code; code in the range 300..399 required
     */
    public function testBadRedirectCode()
    {
        new MovedResourceHandler(Uri\Http::createFromString("/new/path"), Status::CREATED);
    }

    public function testSuccessfulRedirect()
    {
        $action = new MovedResourceHandler(Uri\Http::createFromString("/new/path"), Status::MOVED_PERMANENTLY);
        $uri = Uri\Http::createFromString("http://test.local/foo");
        $request = new Request($this->createMock(Client::class), "GET", $uri);

        /** @var \Amp\Http\Server\Response $response */
        $response = wait($action->handleRequest($request));

        $this->assertSame(Status::MOVED_PERMANENTLY, $response->getStatus());
        $this->assertSame("/new/path", $response->getHeader("location"));
    }

    public function testRequestWithQuery()
    {
        $action = new MovedResourceHandler(Uri\Http::createFromString("/new/path"), Status::MOVED_PERMANENTLY);
        $uri = Uri\Http::createFromString("http://test.local/foo?key=value");
        $request = new Request($this->createMock(Client::class), "GET", $uri);

        /** @var \Amp\Http\Server\Response $response */
        $response = wait($action->handleRequest($request));

        $this->assertSame(Status::MOVED_PERMANENTLY, $response->getStatus());
        $this->assertSame("/new/path?key=value", $response->getHeader("location"));
    }
}
