<?php

namespace Amp\Http\Server\Test;

use Amp\ByteStream\InMemoryStream;
use Amp\Http\Cookie\RequestCookie;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\MissingAttributeError;
use Amp\Http\Server\Request;
use Amp\PHPUnit\TestCase;
use League\Uri\Http;

class RequestTest extends TestCase {
    public function testGetClient() {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'));
        $this->assertSame($client, $request->getClient());
    }

    public function testSetMethod() {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'));
        $this->assertSame('GET', $request->getMethod());
        $request->setMethod('POST');
        $this->assertSame('POST', $request->getMethod());
    }

    public function testSetUri() {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'));
        $this->assertSame('/', (string) $request->getUri());
        $request->setUri(Http::createFromString('/foobar'));
        $this->assertSame('/foobar', (string) $request->getUri());
    }

    public function testSetProtocolVersion() {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'));
        $this->assertSame('1.1', $request->getProtocolVersion());
        $request->setProtocolVersion('1.0');
        $this->assertSame('1.0', $request->getProtocolVersion());
    }

    public function testGetHeader() {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'), [
            'foo' => 'bar',
        ]);

        $this->assertSame('bar', $request->getHeader('foo'));
        $this->assertSame('bar', $request->getHeader('FOO'));
        $this->assertSame('bar', $request->getHeader('FoO'));
        $this->assertNull($request->getHeader('bar'));

        $this->assertSame(['bar'], $request->getHeaderArray('foo'));
        $this->assertSame([], $request->getHeaderArray('bar'));
    }

    public function testAddHeader() {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'), [
            'foo' => 'bar',
        ]);

        $this->assertSame(['bar'], $request->getHeaderArray('foo'));

        $request->addHeader('foo', 'bar');
        $this->assertSame(['bar', 'bar'], $request->getHeaderArray('foo'));

        $request->addHeader('bar', 'bar');
        $this->assertSame(['bar'], $request->getHeaderArray('bar'));
    }

    public function testSetHeader() {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'), [
            'foo' => 'bar',
        ]);

        $this->assertSame(['bar'], $request->getHeaderArray('foo'));

        $request->setHeader('foo', 'bar');
        $this->assertSame(['bar'], $request->getHeaderArray('foo'));

        $request->setHeader('bar', 'bar');
        $this->assertSame(['bar'], $request->getHeaderArray('bar'));

        $request->setHeaders(['bar' => []]);
        $this->assertSame(['bar'], $request->getHeaderArray('foo'));
        $this->assertSame([], $request->getHeaderArray('bar'));
    }

    public function testGetAttribute() {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'));

        $request->setAttribute('foo', 'bar');
        $this->assertSame('bar', $request->getAttribute('foo'));

        $this->expectException(MissingAttributeError::class);
        $request->getAttribute('bar');
    }

    public function testSetBody() {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'POST', Http::createFromString('/'), [
            'content-length' => '0',
        ]);

        $this->assertSame('0', $request->getHeader('content-length'));
        $request->setBody('foobar');
        $this->assertSame('6', $request->getHeader('content-length'));
        $request->setBody('');
        $this->assertSame('0', $request->getHeader('content-length'));

        // A stream being set MUST remove the content length header
        $request->setBody(new InMemoryStream('foobar'));
        $this->assertFalse($request->hasHeader('content-length'));
        $request->setBody(new InMemoryStream('foo'));
        $this->assertFalse($request->hasHeader('content-length'));

        $request = new Request($client, 'GET', Http::createFromString('/'));
        $request->setBody('');
        $this->assertFalse($request->hasHeader('content-length'));
    }

    public function testSetBodyWithConvertibleType() {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'POST', Http::createFromString('/'), [
            'content-length' => '0',
        ]);

        $request->setBody(42);
        $this->assertTrue(true);
    }

    public function testSetBodyWithWrongType() {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'POST', Http::createFromString('/'), [
            'content-length' => '0',
        ]);

        $this->expectException(\TypeError::class);
        $request->setBody(new \stdClass);
    }

    public function testCookies() {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::createFromString('/'), [
            'cookie' => new RequestCookie('foo', 'bar'),
        ]);

        $this->assertNull($request->getCookie('foobar'));
        $this->assertInstanceOf(RequestCookie::class, $request->getCookie('foo'));
        $this->assertCount(1, $request->getCookies());

        $request->removeCookie('foo');
        $this->assertCount(0, $request->getCookies());
        $this->assertFalse($request->hasHeader('cookie'));

        $request->setCookie(new RequestCookie('foo', 'baz'));
        $this->assertCount(1, $request->getCookies());
        $this->assertTrue($request->hasHeader('cookie'));

        $request->removeCookie('foo');
        $request->addHeader('cookie', new RequestCookie('foo'));
        $this->assertCount(1, $request->getCookies());
        $this->assertNotNull($cookie = $request->getCookie('foo'));
        $this->assertSame('', $cookie->getValue());
    }
}
