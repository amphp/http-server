<?php declare(strict_types=1);

namespace Amp\Http\Server\Test;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Cookie\RequestCookie;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\MissingAttributeError;
use Amp\Http\Server\Request;
use Amp\PHPUnit\AsyncTestCase;
use League\Uri\Http;

class RequestTest extends AsyncTestCase
{
    public function testGetClient(): void
    {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'));
        self::assertSame($client, $request->getClient());
    }

    public function testSetMethod(): void
    {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'));
        self::assertSame('GET', $request->getMethod());
        $request->setMethod('POST');
        self::assertSame('POST', $request->getMethod());
    }

    public function testSetUri(): void
    {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'));
        self::assertSame('/', (string) $request->getUri());
        $request->setUri(Http::createFromString('/foobar'));
        self::assertSame('/foobar', (string) $request->getUri());
    }

    public function testSetProtocolVersion(): void
    {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'));
        self::assertSame('1.1', $request->getProtocolVersion());
        $request->setProtocolVersion('1.0');
        self::assertSame('1.0', $request->getProtocolVersion());
    }

    public function testGetHeader(): void
    {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'), [
            'foo' => 'bar',
        ]);

        self::assertSame('bar', $request->getHeader('foo'));
        self::assertSame('bar', $request->getHeader('FOO'));
        self::assertSame('bar', $request->getHeader('FoO'));
        self::assertNull($request->getHeader('bar'));

        self::assertSame(['bar'], $request->getHeaderArray('foo'));
        self::assertSame([], $request->getHeaderArray('bar'));
    }

    public function testAddHeader(): void
    {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'), [
            'foo' => 'bar',
        ]);

        self::assertSame(['bar'], $request->getHeaderArray('foo'));

        $request->addHeader('foo', 'bar');
        self::assertSame(['bar', 'bar'], $request->getHeaderArray('foo'));

        $request->addHeader('bar', 'bar');
        self::assertSame(['bar'], $request->getHeaderArray('bar'));
    }

    public function testSetHeader(): void
    {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'), [
            'foo' => 'bar',
        ]);

        self::assertSame(['bar'], $request->getHeaderArray('foo'));

        $request->setHeader('foo', 'bar');
        self::assertSame(['bar'], $request->getHeaderArray('foo'));

        $request->setHeader('bar', 'bar');
        self::assertSame(['bar'], $request->getHeaderArray('bar'));

        $request->setHeaders(['bar' => []]);
        self::assertSame(['bar'], $request->getHeaderArray('foo'));
        self::assertSame([], $request->getHeaderArray('bar'));
    }

    public function testGetAttribute(): void
    {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'));

        $request->setAttribute('foo', 'bar');
        self::assertSame('bar', $request->getAttribute('foo'));

        $request->setAttribute('bar', 'baz');
        self::assertSame('baz', $request->getAttribute('bar'));

        self::assertSame(['foo' => 'bar', 'bar' => 'baz'], $request->getAttributes());

        $request->removeAttribute('bar');

        self::assertFalse($request->hasAttribute('bar'));

        $request->removeAttributes();

        $this->expectException(MissingAttributeError::class);
        $request->getAttribute('foo');
    }

    public function testSetBody(): void
    {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'POST', Http::createFromString('/'), [
            'content-length' => '0',
        ]);

        self::assertSame('0', $request->getHeader('content-length'));
        $request->setBody('foobar');
        self::assertSame('6', $request->getHeader('content-length'));
        $request->setBody('');
        self::assertSame('0', $request->getHeader('content-length'));

        // A stream being set MUST remove the content length header
        $request->setBody(new ReadableBuffer('foobar'));
        self::assertFalse($request->hasHeader('content-length'));
        $request->setBody(new ReadableBuffer('foo'));
        self::assertFalse($request->hasHeader('content-length'));

        $request = new Request($client, 'GET', Http::createFromString('/'));
        $request->setBody('');
        self::assertFalse($request->hasHeader('content-length'));
    }

    public function testCookies(): void
    {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'), [
            'cookie' => new RequestCookie('foo', 'bar'),
        ]);

        self::assertNull($request->getCookie('foobar'));
        self::assertInstanceOf(RequestCookie::class, $request->getCookie('foo'));
        self::assertCount(1, $request->getCookies());

        $request->removeCookie('foo');
        self::assertCount(0, $request->getCookies());
        self::assertFalse($request->hasHeader('cookie'));

        $request->setCookie(new RequestCookie('foo', 'baz'));
        self::assertCount(1, $request->getCookies());
        self::assertTrue($request->hasHeader('cookie'));

        $request->removeCookie('foo');
        $request->addHeader('cookie', new RequestCookie('foo'));
        self::assertCount(1, $request->getCookies());
        self::assertNotNull($cookie = $request->getCookie('foo'));
        self::assertSame('', $cookie->getValue());
    }
}
