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
        $this->assertFalse($options->isInDebugMode());

        // change
        $this->assertTrue($options->withDebugMode()->isInDebugMode());

        // change doesn't affect original
        $this->assertFalse($options->isInDebugMode());
    }

    public function testWithoutDebugMode(): void
    {
        $options = (new Options)->withDebugMode();

        // default
        $this->assertTrue($options->isInDebugMode());

        // change
        $this->assertFalse($options->withoutDebugMode()->isInDebugMode());

        // change doesn't affect original
        $this->assertTrue($options->isInDebugMode());
    }

    public function testWithConnectionLimit(): void
    {
        $options = new Options;

        // default
        $this->assertSame(10000, $options->getConnectionLimit());

        // change
        $this->assertSame(1, $options->withConnectionLimit(1)->getConnectionLimit());

        // change doesn't affect original
        $this->assertSame(10000, $options->getConnectionLimit());

        // invalid
        $this->expectException(\Error::class);
        $options->withConnectionLimit(0);
    }

    public function testWithConnectionsPerIpLimit(): void
    {
        $options = new Options;

        // default
        $this->assertSame(30, $options->getConnectionsPerIpLimit());

        // change
        $this->assertSame(1, $options->withConnectionsPerIpLimit(1)->getConnectionsPerIpLimit());

        // change doesn't affect original
        $this->assertSame(30, $options->getConnectionsPerIpLimit());

        // invalid
        $this->expectException(\Error::class);
        $options->withConnectionsPerIpLimit(0);
    }

    public function testWithHttp1Timeout(): void
    {
        $options = new Options;

        // default
        $this->assertSame(15, $options->getHttp1Timeout());

        // change
        $this->assertSame(1, $options->withHttp1Timeout(1)->getHttp1Timeout());

        // change doesn't affect original
        $this->assertSame(15, $options->getHttp1Timeout());

        // invalid
        $this->expectException(\Error::class);
        $options->withHttp1Timeout(0);
    }

    public function testWithHttp2Timeout(): void
    {
        $options = new Options;

        // default
        $this->assertSame(60, $options->getHttp2Timeout());

        // change
        $this->assertSame(1, $options->withHttp2Timeout(1)->getHttp2Timeout());

        // change doesn't affect original
        $this->assertSame(60, $options->getHttp2Timeout());

        // invalid
        $this->expectException(\Error::class);
        $options->withHttp2Timeout(0);
    }

    public function testWithTlsSetupTimeout(): void
    {
        $options = new Options;

        // default
        $this->assertSame(5, $options->getTlsSetupTimeout());

        // change
        $this->assertSame(1, $options->withTlsSetupTimeout(1)->getTlsSetupTimeout());

        // change doesn't affect original
        $this->assertSame(5, $options->getTlsSetupTimeout());

        // invalid
        $this->expectException(\Error::class);
        $options->withTlsSetupTimeout(0);
    }

    public function testWithBodySizeLimit(): void
    {
        $options = new Options;

        // default
        $this->assertSame(128 * 1024, $options->getBodySizeLimit());

        // change
        $this->assertSame(0, $options->withBodySizeLimit(0)->getBodySizeLimit());

        // change doesn't affect original
        $this->assertSame(128 * 1024, $options->getBodySizeLimit());

        // invalid
        $this->expectException(\Error::class);
        $options->withBodySizeLimit(-1);
    }

    public function testWithHeaderSizeLimit(): void
    {
        $options = new Options;

        // default
        $this->assertSame(32768, $options->getHeaderSizeLimit());

        // change
        $this->assertSame(1, $options->withHeaderSizeLimit(1)->getHeaderSizeLimit());

        // change doesn't affect original
        $this->assertSame(32768, $options->getHeaderSizeLimit());

        // invalid
        $this->expectException(\Error::class);
        $options->withHeaderSizeLimit(0);
    }

    public function testWithConcurrentStreamLimit(): void
    {
        $options = new Options;

        // default
        $this->assertSame(256, $options->getConcurrentStreamLimit());

        // change
        $this->assertSame(1, $options->withConcurrentStreamLimit(1)->getConcurrentStreamLimit());

        // change doesn't affect original
        $this->assertSame(256, $options->getConcurrentStreamLimit());

        // invalid
        $this->expectException(\Error::class);
        $options->withConcurrentStreamLimit(0);
    }

    public function testWithChunkSize(): void
    {
        $options = new Options;

        // default
        $this->assertSame(8192, $options->getChunkSize());

        // change
        $this->assertSame(1, $options->withChunkSize(1)->getChunkSize());

        // change doesn't affect original
        $this->assertSame(8192, $options->getChunkSize());

        // invalid
        $this->expectException(\Error::class);
        $options->withChunkSize(0);
    }

    public function testWithStreamThreshold(): void
    {
        $options = new Options;

        // default
        $this->assertSame(8192, $options->getStreamThreshold());

        // change
        $this->assertSame(1, $options->withStreamThreshold(1)->getStreamThreshold());

        // change doesn't affect original
        $this->assertSame(8192, $options->getStreamThreshold());

        // invalid
        $this->expectException(\Error::class);
        $options->withStreamThreshold(0);
    }

    public function testWithAllowedMethods(): void
    {
        $options = new Options;

        // default
        $this->assertSame(["GET", "POST", "PUT", "PATCH", "HEAD", "OPTIONS", "DELETE"], $options->getAllowedMethods());

        // change
        $this->assertSame(["GET", "HEAD"], $options->withAllowedMethods(["GET", "HEAD", "GET"])->getAllowedMethods());

        // change doesn't affect original
        $this->assertSame(["GET", "POST", "PUT", "PATCH", "HEAD", "OPTIONS", "DELETE"], $options->getAllowedMethods());
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
        $this->assertFalse($options->isHttp2UpgradeAllowed());

        // change
        $this->assertTrue($options->withHttp2Upgrade()->isHttp2UpgradeAllowed());

        // change doesn't affect original
        $this->assertFalse($options->isHttp2UpgradeAllowed());
    }

    public function testWithoutHttpUpgrade(): void
    {
        $options = (new Options)->withHttp2Upgrade();

        // default
        $this->assertTrue($options->isHttp2UpgradeAllowed());

        // change
        $this->assertFalse($options->withoutHttp2Upgrade()->isHttp2UpgradeAllowed());

        // change doesn't affect original
        $this->assertTrue($options->isHttp2UpgradeAllowed());
    }

    public function testWithCompression(): void
    {
        $options = new Options;

        // default
        $this->assertTrue($options->isCompressionEnabled());

        // change
        $this->assertFalse($options->withoutCompression()->isCompressionEnabled());

        // change doesn't affect original
        $this->assertTrue($options->isCompressionEnabled());
    }

    public function testWithoutCompression(): void
    {
        $options = (new Options)->withoutCompression();

        // default
        $this->assertFalse($options->isCompressionEnabled());

        // change
        $this->assertTrue($options->withCompression()->isCompressionEnabled());

        // change doesn't affect original
        $this->assertFalse($options->isCompressionEnabled());
    }
}
