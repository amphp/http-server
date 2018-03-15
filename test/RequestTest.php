<?php

namespace Amp\Http\Server\Test;

use Amp\Http\Server\Client;
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
}