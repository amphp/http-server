<?php

namespace Amp\Http\Server\Test;

use Amp\Http\Server\Options;
use PHPUnit\Framework\TestCase;

class OptionsTest extends TestCase
{
    public function testWithDebugMode(): void
    {
        $options = new Options;

        // default
        self::assertFalse($options->isInDebugMode());

        // change
        self::assertTrue($options->withDebugMode()->isInDebugMode());

        // change doesn't affect original
        self::assertFalse($options->isInDebugMode());
    }

    public function testWithoutDebugMode(): void
    {
        $options = (new Options)->withDebugMode();

        // default
        self::assertTrue($options->isInDebugMode());

        // change
        self::assertFalse($options->withoutDebugMode()->isInDebugMode());

        // change doesn't affect original
        self::assertTrue($options->isInDebugMode());
    }

    public function testWithConnectionLimit(): void
    {
        $options = new Options;

        // default
        self::assertSame(10000, $options->getConnectionLimit());

        // change
        self::assertSame(1, $options->withConnectionLimit(1)->getConnectionLimit());

        // change doesn't affect original
        self::assertSame(10000, $options->getConnectionLimit());

        // invalid
        $this->expectException(\Error::class);
        $options->withConnectionLimit(0);
    }

    public function testWithConnectionsPerIpLimit(): void
    {
        $options = new Options;

        // default
        self::assertSame(30, $options->getConnectionsPerIpLimit());

        // change
        self::assertSame(1, $options->withConnectionsPerIpLimit(1)->getConnectionsPerIpLimit());

        // change doesn't affect original
        self::assertSame(30, $options->getConnectionsPerIpLimit());

        // invalid
        $this->expectException(\Error::class);
        $options->withConnectionsPerIpLimit(0);
    }

    public function testWithHttp1Timeout(): void
    {
        $options = new Options;

        // default
        self::assertSame(15, $options->getHttp1Timeout());

        // change
        self::assertSame(1, $options->withHttp1Timeout(1)->getHttp1Timeout());

        // change doesn't affect original
        self::assertSame(15, $options->getHttp1Timeout());

        // invalid
        $this->expectException(\Error::class);
        $options->withHttp1Timeout(0);
    }

    public function testWithHttp2Timeout(): void
    {
        $options = new Options;

        // default
        self::assertSame(60, $options->getHttp2Timeout());

        // change
        self::assertSame(1, $options->withHttp2Timeout(1)->getHttp2Timeout());

        // change doesn't affect original
        self::assertSame(60, $options->getHttp2Timeout());

        // invalid
        $this->expectException(\Error::class);
        $options->withHttp2Timeout(0);
    }

    public function testWithTlsSetupTimeout(): void
    {
        $options = new Options;

        // default
        self::assertSame(5, $options->getTlsSetupTimeout());

        // change
        self::assertSame(1, $options->withTlsSetupTimeout(1)->getTlsSetupTimeout());

        // change doesn't affect original
        self::assertSame(5, $options->getTlsSetupTimeout());

        // invalid
        $this->expectException(\Error::class);
        $options->withTlsSetupTimeout(0);
    }

    public function testWithBodySizeLimit(): void
    {
        $options = new Options;

        // default
        self::assertSame(128 * 1024, $options->getBodySizeLimit());

        // change
        self::assertSame(0, $options->withBodySizeLimit(0)->getBodySizeLimit());

        // change doesn't affect original
        self::assertSame(128 * 1024, $options->getBodySizeLimit());

        // invalid
        $this->expectException(\Error::class);
        $options->withBodySizeLimit(-1);
    }

    public function testWithHeaderSizeLimit(): void
    {
        $options = new Options;

        // default
        self::assertSame(32768, $options->getHeaderSizeLimit());

        // change
        self::assertSame(1, $options->withHeaderSizeLimit(1)->getHeaderSizeLimit());

        // change doesn't affect original
        self::assertSame(32768, $options->getHeaderSizeLimit());

        // invalid
        $this->expectException(\Error::class);
        $options->withHeaderSizeLimit(0);
    }

    public function testWithConcurrentStreamLimit(): void
    {
        $options = new Options;

        // default
        self::assertSame(256, $options->getConcurrentStreamLimit());

        // change
        self::assertSame(1, $options->withConcurrentStreamLimit(1)->getConcurrentStreamLimit());

        // change doesn't affect original
        self::assertSame(256, $options->getConcurrentStreamLimit());

        // invalid
        $this->expectException(\Error::class);
        $options->withConcurrentStreamLimit(0);
    }

    public function testWithChunkSize(): void
    {
        $options = new Options;

        // default
        self::assertSame(8192, $options->getChunkSize());

        // change
        self::assertSame(1, $options->withChunkSize(1)->getChunkSize());

        // change doesn't affect original
        self::assertSame(8192, $options->getChunkSize());

        // invalid
        $this->expectException(\Error::class);
        $options->withChunkSize(0);
    }

    public function testWithStreamThreshold(): void
    {
        $options = new Options;

        // default
        self::assertSame(8192, $options->getStreamThreshold());

        // change
        self::assertSame(1, $options->withStreamThreshold(1)->getStreamThreshold());

        // change doesn't affect original
        self::assertSame(8192, $options->getStreamThreshold());

        // invalid
        $this->expectException(\Error::class);
        $options->withStreamThreshold(0);
    }

    public function testWithAllowedMethods(): void
    {
        $options = new Options;

        // default
        self::assertSame(["GET", "POST", "PUT", "PATCH", "HEAD", "OPTIONS", "DELETE"], $options->getAllowedMethods());

        // change
        self::assertSame(["GET", "HEAD"], $options->withAllowedMethods(["GET", "HEAD", "GET"])->getAllowedMethods());

        // change doesn't affect original
        self::assertSame(["GET", "POST", "PUT", "PATCH", "HEAD", "OPTIONS", "DELETE"], $options->getAllowedMethods());
    }

    public function testWithAllowedMethodsWithoutGet(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Servers must support GET");
        (new Options)->withAllowedMethods(["HEAD"]);
    }

    public function testWithAllowedMethodsWithInvalidType(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid type at key 0 of allowed methods array: integer");
        (new Options)->withAllowedMethods([42]);
    }

    public function testWithAllowedMethodsWithEmptyMethod(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid empty HTTP method");
        (new Options)->withAllowedMethods(["HEAD", "GET", ""]);
    }

    public function testWithAllowedMethodsWithoutHead(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Servers must support HEAD");
        (new Options)->withAllowedMethods(["GET"]);
    }

    public function testWithHttpUpgrade(): void
    {
        $options = new Options;

        // default
        self::assertFalse($options->isHttp2UpgradeAllowed());

        // change
        self::assertTrue($options->withHttp2Upgrade()->isHttp2UpgradeAllowed());

        // change doesn't affect original
        self::assertFalse($options->isHttp2UpgradeAllowed());
    }

    public function testWithoutHttpUpgrade(): void
    {
        $options = (new Options)->withHttp2Upgrade();

        // default
        self::assertTrue($options->isHttp2UpgradeAllowed());

        // change
        self::assertFalse($options->withoutHttp2Upgrade()->isHttp2UpgradeAllowed());

        // change doesn't affect original
        self::assertTrue($options->isHttp2UpgradeAllowed());
    }

    public function testWithoutPush(): void
    {
        $options = new Options;

        // default
        self::assertTrue($options->isPushEnabled());

        // change
        self::assertFalse($options->withoutPush()->isPushEnabled());

        // change doesn't affect original
        self::assertTrue($options->isPushEnabled());
    }

    public function testWithPush(): void
    {
        $options = (new Options)->withoutPush();

        // default
        self::assertFalse($options->isPushEnabled());

        // change
        self::assertTrue($options->withPush()->isPushEnabled());

        // change doesn't affect original
        self::assertFalse($options->isPushEnabled());
    }

    public function testWithCompression(): void
    {
        $options = new Options;

        // default
        self::assertTrue($options->isCompressionEnabled());

        // change
        self::assertFalse($options->withoutCompression()->isCompressionEnabled());

        // change doesn't affect original
        self::assertTrue($options->isCompressionEnabled());
    }

    public function testWithoutCompression(): void
    {
        $options = (new Options)->withoutCompression();

        // default
        self::assertFalse($options->isCompressionEnabled());

        // change
        self::assertTrue($options->withCompression()->isCompressionEnabled());

        // change doesn't affect original
        self::assertFalse($options->isCompressionEnabled());
    }
}
