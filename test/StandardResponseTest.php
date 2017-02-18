<?php

namespace Aerys\Test;

use Aerys\{ Client, Filter, InternalRequest, Response, StandardResponse, function responseFilter };

class StandardResponseTest extends \PHPUnit_Framework_TestCase {

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot set status code; output already started
     */
    public function testSetStatusErrorsIfResponseAlreadyStarted() {
        $response = new StandardResponse((function() { while (1) yield; })(), new Client);
        $response->stream("test");
        $response->setStatus(200);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot set reason phrase; output already started
     */
    public function testSetReasonErrorsIfResponseAlreadyStarted() {
        $response = new StandardResponse((function() { while (1) yield; })(), new Client);
        $response->stream("test");
        $response->setReason("zanzibar");
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot add header; output already started
     */
    public function testAddHeaderErrorsIfResponseAlreadyStarted() {
        $response = new StandardResponse((function() { while (1) yield; })(), new Client);
        $response->stream("test");
        $response->addHeader("Content-Length", "42");
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot set header; output already started
     */
    public function testSetHeaderErrorsIfResponseAlreadyStarted() {
        $response = new StandardResponse((function() { while (1) yield; })(), new Client);
        $response->stream("test");
        $response->setHeader("Content-Length", "42");
    }

    public function testSendUpdatesResponseState() {
        $headers = [];
        $received = "";
        $writer = function() use (&$headers, &$received) {
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
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot stream: response already sent
     */
    public function testSendThrowsIfResponseAlreadyComplete() {
        $response = new StandardResponse((function() { while (1) yield; })(), new Client);
        $response->end("test");
        $response->stream("this should throw");
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot stream: response already sent
     */
    public function testStreamThrowsIfResponseAlreadySent() {
        $response = new StandardResponse((function() { while (1) yield; })(), new Client);
        $response->end("test");
        $response->stream("this should throw");
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot stream: response already sent
     */
    public function testStreamThrowsIfResponseAlreadyEnded() {
        $response = new StandardResponse((function() { while (1) yield; })(), new Client);
        $response->end();
        $response->stream("this should throw");
    }

    public function testMultiStream() {
        $headers = [];
        $received = "";
        $writer = function() use (&$headers, &$received) {
            $headers = yield;
            while (true) {
                $received .= yield;
            }
        };

        $response = new StandardResponse($writer(), new Client);
        $response->stream("foo\n");
        $response->stream("bar\n");
        $response->stream("baz\n");
        $response->end("bat\n");
        $expected = [":aerys-entity-length" => "*", ":reason" => "OK", ":status" => 200];
        $this->assertEquals($expected, $headers);
        $this->assertSame("foo\nbar\nbaz\nbat\n", $received);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot flush: response already sent
     */
    public function testFlushThrowsIfResponseAlreadySent() {
        $response = new StandardResponse((function() { while (1) yield; })(), new Client);
        $response->end();
        $response->flush();
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot flush: response output not started
     */
    public function testFlushThrowsIfResponseOutputNotStarted() {
        $response = new StandardResponse((function() { while (1) yield; })(), new Client);
        $response->flush();
    }
}
