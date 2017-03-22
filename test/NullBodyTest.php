<?php

namespace Aerys\Test;

use Amp\Success;
use Aerys\NullBody;

class NullBodyTest extends \PHPUnit_Framework_TestCase {
    public function testBufferReturnsFulfilledPromiseWithEmptyString() {
        $body = new NullBody;
        $invoked = false;
        $result = null;
        $body->onResolve(function($e, $r) use (&$invoked, &$result) {
            $this->assertNull($e);
            $invoked = true;
            $result = $r;
        });
        $body->advance()->onResolve(function($e, $emitted) {
            $this->assertFalse($emitted);
        });
        $this->assertTrue($invoked);
        $this->assertSame("", $result);
    }
}
