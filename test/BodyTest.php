<?php

namespace Aerys\Test;

use Aerys\ClientSizeException;
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

    public function testFinishedStream() {
        $deferred = new Deferred;
        $body = new Body($deferred->promise());

        $body->watch(function ($data) {
            $this->assertEquals("text", $data);
        });

        $deferred->update("text");
        $deferred->update("text");
        $deferred->succeed();
        $body->when(function ($e, $result) {
            $this->assertEquals("texttext", $result);
            $this->assertNull($e);
        });
        \Amp\run(function() use ($body) {
            $payload = "";
            while (yield $body->valid()) {
                $payload .= $body->consume();
            }
            $this->assertEquals("texttext", $payload);
        });
    }
    
    public function testFailedFinishedStream() {
        $deferred = new Deferred;
        $body = new Body($deferred->promise());

        $body->watch(function ($data) {
            $this->assertEquals("text", $data);
        });

        $deferred->update("text");
        $deferred->update("text");
        $deferred->fail(new ClientSizeException);
        $body->when(function ($e, $result) {
            $this->assertEquals("texttext", $result);
            $this->assertInstanceOf(ClientSizeException::class, $e);
        });
        \Amp\run(function() use ($body) {
            $payload = "";
            try {
                while (yield $body->valid()) {
                    $payload .= $body->consume();
                }
            } catch (ClientSizeException $e) {}

            $this->assertInstanceOf(ClientSizeException::class, $e);
            $this->assertEquals("texttext", $payload);
        });
    }
}
