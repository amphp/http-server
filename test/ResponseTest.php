<?php declare(strict_types=1);

namespace Amp\Http\Server\Test;

use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Response;
use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\PHPUnit\UnhandledException;
use function Amp\delay;

class ResponseTest extends AsyncTestCase
{
    public function testDispose(): void
    {
        $response = new Response;
        $response->onDispose($this->createCallback(1));
        $response = null;
    }

    public function testDisposeThrowing(): void
    {
        $this->expectException(UnhandledException::class);

        $response = new Response;
        $response->onDispose(function () {
            throw new TestException;
        });

        unset($response);

        delay(10);
    }

    public function testCookies(): void
    {
        $request = new Response(HttpStatus::OK, [
            'set-cookie' => (string) new ResponseCookie('foo', 'bar'),
        ]);

        self::assertNull($request->getCookie('foobar'));
        self::assertInstanceOf(ResponseCookie::class, $request->getCookie('foo'));
        self::assertCount(1, $request->getCookies());

        $request->removeCookie('foo');
        self::assertCount(0, $request->getCookies());
        self::assertFalse($request->hasHeader('set-cookie'));

        $request->setCookie(new ResponseCookie('foo', 'baz'));
        self::assertCount(1, $request->getCookies());
        self::assertTrue($request->hasHeader('set-cookie'));

        $request->removeCookie('foo');
        $request->addHeader('set-cookie', (string) new ResponseCookie('foo'));
        self::assertCount(1, $request->getCookies());
        self::assertNotNull($cookie = $request->getCookie('foo'));
        self::assertSame('', $cookie->getValue());
    }

    public function testStatusCodeOutOfRangeBelow(): void
    {
        $this->expectException(\Error::class);
        new Response(99);
    }

    public function testStatusCodeOutOfRangeAbove(): void
    {
        $this->expectException(\Error::class);
        new Response(600);
    }

    public function testUndoUpgrade(): void
    {
        $response = new Response;
        self::assertNull($response->getUpgradeHandler());

        $response->upgrade($this->createCallback(0));
        self::assertNotNull($response->getUpgradeHandler());

        $response->setStatus(500);
        self::assertNull($response->getUpgradeHandler());
    }
}
