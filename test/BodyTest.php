<?php declare(strict_types = 1);

namespace Aerys\Test;

use Aerys\ClientSizeException;
use Amp\Postponed;
use Aerys\Body;

class BodyTest extends \PHPUnit_Framework_TestCase {
    public function testPromiseImplementation() {
        $postponed = new Postponed;
        $body = new Body($postponed->getObservable());

        $body->when(function ($e, $result) use (&$when) {
            $this->assertNull($e);
            $when = $result;
        });

        $postponed->resolve();
        $this->assertEquals("", $when);
    }

    public function testStream() {
        $postponed = new Postponed;
        $body = new Body($postponed->getObservable());

        $body->subscribe(function ($data) {
            $this->assertEquals("text", $data);
        });
        $body->when(function ($e, $result) use (&$when) {
            $this->assertNull($e);
            $when = $result;
        });

        $postponed->emit("text");
        $postponed->emit("text");
        $postponed->resolve();
        $this->assertEquals("texttext", $when);
    }

    public function testFinishedStream() {
        $postponed = new Postponed;
        $body = new Body($postponed->getObservable());

        $body->subscribe(function ($data) {
            $this->assertEquals("text", $data);
        });

        $postponed->emit("text");
        $postponed->emit("text");
        $postponed->resolve();
        $body->when(function ($e, $result) {
            $this->assertEquals("texttext", $result);
            $this->assertNull($e);
        });
        \Amp\execute(function() use ($body) {
            $payload = "";
            while (yield $body->next()) {
                $payload .= $body->getCurrent();
            }
            $this->assertEquals("texttext", $payload);
        });
    }
    
    public function testFailedFinishedStream() {
        $postponed = new Postponed;
        $body = new Body($postponed->getObservable());

        $body->subscribe(function ($data) {
            $this->assertEquals("text", $data);
        });

        $postponed->emit("text");
        $postponed->emit("text");
        $postponed->fail(new ClientSizeException);
        $body->when(function ($e, $result) {
            $this->assertEquals("texttext", $result);
            $this->assertInstanceOf(ClientSizeException::class, $e);
        });
        \Amp\execute(function() use ($body) {
            $payload = "";
            try {
                while (yield $body->next()) {
                    $payload .= $body->getCurrent();
                }
            } catch (ClientSizeException $e) {}

            $this->assertInstanceOf(ClientSizeException::class, $e);
            $this->assertEquals("texttext", $payload);
        });
    }
}
