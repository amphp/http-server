<?php

namespace Aerys\Test;

use Amp\Loop;
use Aerys\NullBody;
use PHPUnit\Framework\TestCase;

class NullBodyTest extends TestCase {
    public function testBufferReturnsFulfilledPromiseWithEmptyString() {
        Loop::run(function() {
            $body = new NullBody;
            $this->assertEquals("", yield $body->read());
            $this->assertSame("", yield $body);
        });
    }
}
