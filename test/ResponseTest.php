<?php

namespace Amp\Http\Server\Test;

use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\PHPUnit\TestException;

class ResponseTest extends TestCase
{
    public function testDispose()
    {
        $response = new Response;
        $response->onDispose($this->createCallback(1));
        $response = null;
    }

    public function testDisposeThrowing()
    {
        $response = new Response;
        $response->onDispose(function () {
            throw new TestException;
        });
        $response = null;

        $this->expectException(TestException::class);
        Loop::run(function () {
            Loop::stop();
        });
    }

    public function testSetBodyWithConvertibleType()
    {
        $response = new Response;
        $response->setBody(42);
        $this->assertTrue(true);
    }

    public function testSetBodyWithWrongType()
    {
        $response = new Response;
        $this->expectException(\TypeError::class);
        $response->setBody(new \stdClass);
    }

    public function testCookies()
    {
        $request = new Response(Status::OK, [
            'set-cookie' => new ResponseCookie('foo', 'bar'),
        ]);

        $this->assertNull($request->getCookie('foobar'));
        $this->assertInstanceOf(ResponseCookie::class, $request->getCookie('foo'));
        $this->assertCount(1, $request->getCookies());

        $request->removeCookie('foo');
        $this->assertCount(0, $request->getCookies());
        $this->assertFalse($request->hasHeader('set-cookie'));

        $request->setCookie(new ResponseCookie('foo', 'baz'));
        $this->assertCount(1, $request->getCookies());
        $this->assertTrue($request->hasHeader('set-cookie'));

        $request->removeCookie('foo');
        $request->addHeader('set-cookie', new ResponseCookie('foo'));
        $this->assertCount(1, $request->getCookies());
        $this->assertNotNull($cookie = $request->getCookie('foo'));
        $this->assertSame('', $cookie->getValue());
    }

    public function testStatusCodeOutOfRangeBelow()
    {
        $this->expectException(\Error::class);
        new Response(99);
    }

    public function testStatusCodeOutOfRangeAbove()
    {
        $this->expectException(\Error::class);
        new Response(600);
    }

    public function testUndoUpgrade()
    {
        $response = new Response;
        $this->assertNull($response->getUpgradeCallable());

        $response->upgrade($this->createCallback(0));
        $this->assertNotNull($response->getUpgradeCallable());

        $response->setStatus(500);
        $this->assertNull($response->getUpgradeCallable());
    }
}
