<?php

namespace Aerys\Test;

use Aerys\Client;
use Aerys\Response;
use Aerys\StandardResponse;
use PHPUnit\Framework\TestCase;

class StandardResponseTest extends TestCase {

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Cannot set status code; output already started
     */
    public function testSetStatusErrorsIfResponseAlreadyStarted() {
        $response = new StandardResponse((function () { while (1) { yield; } })(), new Client);
        $response->write("test");
        $response->setStatus(200);
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Cannot set reason phrase; output already started
     */
    public function testSetReasonErrorsIfResponseAlreadyStarted() {
        $response = new StandardResponse((function () { while (1) { yield; } })(), new Client);
        $response->write("test");
        $response->setReason("zanzibar");
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Cannot add header; output already started
     */
    public function testAddHeaderErrorsIfResponseAlreadyStarted() {
        $response = new StandardResponse((function () { while (1) { yield; } })(), new Client);
        $response->write("test");
        $response->addHeader("Content-Length", "42");
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Cannot set header; output already started
     */
    public function testSetHeaderErrorsIfResponseAlreadyStarted() {
        $response = new StandardResponse((function () { while (1) { yield; } })(), new Client);
        $response->write("test");
        $response->setHeader("Content-Length", "42");
    }

    public function testSendUpdatesResponseState() {
        $headers = [];
        $received = "";
        $writer = function () use (&$headers, &$received) {
            $headers = yield;
            while (true) {
                $received .= yield;
            }
        };

        $response = new StandardResponse($writer(), new Client);
        $response->end("test");
        $expected = [":aerys-entity-length" => 4, ":reason" => "OK", ":status" => 200];
        $this->assertEquals($expected, $headers);
        $this->assertSame("test", $received);
        $this->assertTrue((bool) $response->state() && Response::STARTED);
        $this->assertTrue((bool) $response->state() && Response::ENDED);
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Cannot write: response already sent
     */
    public function testSendThrowsIfResponseAlreadyComplete() {
        $response = new StandardResponse((function () { while (1) { yield; } })(), new Client);
        $response->end("test");
        $response->write("this should throw");
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Cannot write: response already sent
     */
    public function testStreamThrowsIfResponseAlreadySent() {
        $response = new StandardResponse((function () { while (1) { yield; } })(), new Client);
        $response->end("test");
        $response->write("this should throw");
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Cannot write: response already sent
     */
    public function testStreamThrowsIfResponseAlreadyEnded() {
        $response = new StandardResponse((function () { while (1) { yield; } })(), new Client);
        $response->end();
        $response->write("this should throw");
    }

    public function testMultiStream() {
        $headers = [];
        $received = "";
        $writer = function () use (&$headers, &$received) {
            $headers = yield;
            while (true) {
                $received .= yield;
            }
        };

        $response = new StandardResponse($writer(), new Client);
        $response->write("foo\n");
        $response->write("bar\n");
        $response->write("baz\n");
        $response->end("bat\n");
        $expected = [":aerys-entity-length" => "*", ":reason" => "OK", ":status" => 200];
        $this->assertEquals($expected, $headers);
        $this->assertSame("foo\nbar\nbaz\nbat\n", $received);
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Cannot flush: response already sent
     */
    public function testFlushThrowsIfResponseAlreadySent() {
        $response = new StandardResponse((function () { while (1) { yield; } })(), new Client);
        $response->end();
        $response->flush();
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Cannot flush: response output not started
     */
    public function testFlushThrowsIfResponseOutputNotStarted() {
        $response = new StandardResponse((function () { while (1) { yield; } })(), new Client);
        $response->flush();
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Cannot write: response already sent
     */
    public function testSendThrowsIfResponseAborted() {
        $response = new StandardResponse((function () { while (1) { yield; } })(), new Client);
        $response->abort();
        $response->write("this should throw");
    }

    public function testAbort() {
        $client = new Client;
        $response = new StandardResponse((function () use (&$invoked) {
            try {
                yield;
                $started = true;
            } finally {
                $this->assertFalse(isset($started));
                $invoked = true;
            }
        })(), $client);

        $this->assertNull($invoked);
        $response->abort();
        $this->assertTrue($invoked);
    }
}
