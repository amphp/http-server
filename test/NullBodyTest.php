<?php

namespace Aerys\Test;

use Aerys\NullBody;
use Amp\Loop;
use PHPUnit\Framework\TestCase;

class NullBodyTest extends TestCase {
    public function testBufferReturnsFulfilledPromiseWithEmptyString() {
        Loop::run(function () {
            $body = new NullBody;
            $this->assertEquals("", yield $body->read());
            $this->assertSame("", yield $body);
        });
    }
}
