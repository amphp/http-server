<?php

namespace Amp\Http\Server\Test;

use Amp\Http\Server\Options;
use Amp\PHPUnit\TestCase;

class OptionsTest extends TestCase
{
    public function testWithDebugMode()
    {
        $options = new Options;

        // default
        $this->assertFalse($options->isInDebugMode());

        // change
        $this->assertTrue($options->withDebugMode()->isInDebugMode());

        // change doesn't affect original
        $this->assertFalse($options->isInDebugMode());
    }

    public function testWithoutDebugMode()
    {
        $options = (new Options)->withDebugMode();

        // default
        $this->assertTrue($options->isInDebugMode());

        // change
        $this->assertFalse($options->withoutDebugMode()->isInDebugMode());

        // change doesn't affect original
        $this->assertTrue($options->isInDebugMode());
    }

    public function testWithConnectionLimit()
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

    public function testWithConnectionsPerIpLimit()
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

    public function testWithConnectionTimeout()
    {
        $options = new Options;

        // default
        $this->assertSame(15, $options->getConnectionTimeout());

        // change
        $this->assertSame(1, $options->withConnectionTimeout(1)->getConnectionTimeout());

        // change doesn't affect original
        $this->assertSame(15, $options->getConnectionTimeout());

        // invalid
        $this->expectException(\Error::class);
        $options->withConnectionTimeout(0);
    }

    public function testWithBodySizeLimit()
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

    public function testWithHeaderSizeLimit()
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

    public function testWithConcurrentStreamLimit()
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

    public function testWithMinimumAverageFrameSize()
    {
        $options = new Options;

        // default
        $this->assertSame(1024, $options->getMinimumAverageFrameSize());

        // change
        $this->assertSame(1, $options->withMinimumAverageFrameSize(1)->getMinimumAverageFrameSize());

        // change doesn't affect original
        $this->assertSame(1024, $options->getMinimumAverageFrameSize());

        // invalid
        $this->expectException(\Error::class);
        $options->withMinimumAverageFrameSize(0);
    }

    public function testWithFramesPerSecondLimit()
    {
        $options = new Options;

        // default
        $this->assertSame(1024, $options->getFramesPerSecondLimit());

        // change
        $this->assertSame(1, $options->withFramesPerSecondLimit(1)->getFramesPerSecondLimit());

        // change doesn't affect original
        $this->assertSame(1024, $options->getFramesPerSecondLimit());

        // invalid
        $this->expectException(\Error::class);
        $options->withFramesPerSecondLimit(0);
    }

    public function testWithChunkSize()
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

    public function testWithStreamThreshold()
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

    public function testWithAllowedMethods()
    {
        $options = new Options;

        // default
        $this->assertSame(["GET", "POST", "PUT", "PATCH", "HEAD", "OPTIONS", "DELETE"], $options->getAllowedMethods());

        // change
        $this->assertSame(["GET", "HEAD"], $options->withAllowedMethods(["GET", "HEAD", "GET"])->getAllowedMethods());

        // change doesn't affect original
        $this->assertSame(["GET", "POST", "PUT", "PATCH", "HEAD", "OPTIONS", "DELETE"], $options->getAllowedMethods());
    }

    public function testWithAllowedMethodsWithoutGet()
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Servers must support GET");
        (new Options)->withAllowedMethods(["HEAD"]);
    }

    public function testWithAllowedMethodsWithInvalidType()
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid type at key 0 of allowed methods array: integer");
        (new Options)->withAllowedMethods([42]);
    }

    public function testWithAllowedMethodsWithEmptyMethod()
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid empty HTTP method");
        (new Options)->withAllowedMethods(["HEAD", "GET", ""]);
    }

    public function testWithAllowedMethodsWithoutHead()
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Servers must support HEAD");
        (new Options)->withAllowedMethods(["GET"]);
    }

    public function testWithHttpUpgrade()
    {
        $options = new Options;

        // default
        $this->assertFalse($options->isHttp2UpgradeAllowed());

        // change
        $this->assertTrue($options->withHttp2Upgrade()->isHttp2UpgradeAllowed());

        // change doesn't affect original
        $this->assertFalse($options->isHttp2UpgradeAllowed());
    }

    public function testWithoutHttpUpgrade()
    {
        $options = (new Options)->withHttp2Upgrade();

        // default
        $this->assertTrue($options->isHttp2UpgradeAllowed());

        // change
        $this->assertFalse($options->withoutHttp2Upgrade()->isHttp2UpgradeAllowed());

        // change doesn't affect original
        $this->assertTrue($options->isHttp2UpgradeAllowed());
    }

    public function testWithCompression()
    {
        $options = new Options;

        // default
        $this->assertTrue($options->isCompressionEnabled());

        // change
        $this->assertFalse($options->withoutCompression()->isCompressionEnabled());

        // change doesn't affect original
        $this->assertTrue($options->isCompressionEnabled());
    }

    public function testWithoutCompression()
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
