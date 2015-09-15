<?php

namespace Aerys\Test;

use Amp\Deferred;
use Aerys\Body;

class BodyTest extends \PHPUnit_Framework_TestCase {
    public function testPromiseImplementation() {
        $deferred = new Deferred;
        $body = new Body($deferred->promise());

        $body->when(function ($e, $result) use (&$when) {
            $this->assertNull($e);
            $when = $result;
        });

        $deferred->succeed();
        $this->assertEquals("", $when);
    }

    public function testStream() {
        $deferred = new Deferred;
        $body = new Body($deferred->promise());

        $body->watch(function ($data) {
            $this->assertEquals("text", $data);
        });
        $body->when(function ($e, $result) use (&$when) {
            $this->assertNull($e);
            $when = $result;
        });

        $deferred->update("text");
        $deferred->update("text");
        $deferred->succeed();
        $this->assertEquals("texttext", $when);
    }
}
