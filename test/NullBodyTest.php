<?php

namespace Aerys\Test;

use Amp\Success;
use Aerys\NullBody;

class NullBodyTest extends \PHPUnit_Framework_TestCase {
    public function testBufferReturnsFulfilledPromiseWithEmptyString() {
        $body = new NullBody;
        $buffer = $body->buffer();
        $this->assertInstanceOf("Amp\Success", $buffer);
        $invoked = false;
        $result = null;
        $buffer->when(function($e, $r) use (&$invoked, &$result) {
            $invoked = true;
            $result = $r;
        });
        $this->assertTrue($invoked);
        $this->assertSame("", $result);
    }

    public function testStreamReturnsGeneratorYieldingSingleEmptyStringSuccess() {
        $body = new NullBody;
        $stream = $body->stream();
        $this->assertInstanceOf("Generator", $stream);
        
        $i = 0;
        $result = null;
        foreach ($stream as $promise) {
            $this->assertInstanceof("Amp\Success", $promise);
            $promise->when(function($e, $r) use (&$i, &$result) {
                $i++;
                $result = $r;
            });
        }
        $this->assertSame(1, $i);
        $this->assertSame("", $result);
    }
}
