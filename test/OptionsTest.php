<?php

namespace Amp\Http\Server\Test;

use Amp\Http\Server\Options;
use Amp\PHPUnit\TestCase;

class OptionsTest extends TestCase {
    public function testWithDebugMode() {
        $options = new Options;

        // default
        $this->assertFalse($options->isInDebugMode());

        // change
        $this->assertTrue($options->withDebugMode()->isInDebugMode());

        // change doesn't affect original
        $this->assertFalse($options->isInDebugMode());
    }

    public function testWithoutDebugMode() {
        $options = (new Options)->withDebugMode();

        // default
        $this->assertTrue($options->isInDebugMode());

        // change
        $this->assertFalse($options->withoutDebugMode()->isInDebugMode());

        // change doesn't affect original
        $this->assertTrue($options->isInDebugMode());
    }
}
