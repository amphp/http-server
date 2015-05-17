<?php

namespace Aerys\Test;

use Aerys\{ Filter, Response, StandardResponse };

class StandardResponseTest extends \PHPUnit_Framework_TestCase {

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot set status code; output already started
     */
    public function testSetStatusErrorsIfResponseAlreadyStarted() {
        $received = "";
        $writer = function() use (&$received) {
            while (true) {
                $received .= yield;
            }
        };

        $response = new StandardResponse(new Filter([]), $writer());
        $response->send("test");
        $expected = "{proto} 200 \r\n__Aerys-Entity-Length: 4\r\n\r\ntest";
        $this->assertSame($expected, $received);
        $response->setStatus(200);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot set reason phrase; output already started
     */
    public function testSetReasonErrorsIfResponseAlreadyStarted() {
        $received = "";
        $writer = function() use (&$received) {
            while (true) {
                $received .= yield;
            }
        };

        $response = new StandardResponse(new Filter([]), $writer());
        $response->send("test");
        $expected = "{proto} 200 \r\n__Aerys-Entity-Length: 4\r\n\r\ntest";
        $this->assertSame($expected, $received);
        $response->setReason("zanzibar");
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot add header; output already started
     */
    public function testAddHeaderErrorsIfResponseAlreadyStarted() {
        $received = "";
        $writer = function() use (&$received) {
            while (true) {
                $received .= yield;
            }
        };

        $response = new StandardResponse(new Filter([]), $writer());
        $response->send("test");
        $expected = "{proto} 200 \r\n__Aerys-Entity-Length: 4\r\n\r\ntest";
        $this->assertSame($expected, $received);
        $response->addHeader("Content-Length", "42");
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot set header; output already started
     */
    public function testSetHeaderErrorsIfResponseAlreadyStarted() {
        $received = "";
        $writer = function() use (&$received) {
            while (true) {
                $received .= yield;
            }
        };

        $response = new StandardResponse(new Filter([]), $writer());
        $response->send("test");
        $expected = "{proto} 200 \r\n__Aerys-Entity-Length: 4\r\n\r\ntest";
        $this->assertSame($expected, $received);
        $response->setHeader("Content-Length", "42");
    }

    public function testSendUpdatesResponseState() {
        $received = "";
        $writer = function() use (&$received) {
            while (true) {
                $received .= yield;
            }
        };

        $response = new StandardResponse(new Filter([]), $writer());
        $response->send("test");
        $expected = "{proto} 200 \r\n__Aerys-Entity-Length: 4\r\n\r\ntest";
        $this->assertSame($expected, $received);
        $this->assertTrue((bool) $response->state() && Response::STARTED);
        $this->assertTrue((bool) $response->state() && Response::ENDED);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot send: response already sent
     */
    public function testSendThrowsIfResponseAlreadyComplete() {
        $received = "";
        $writer = function() use (&$received) {
            while (true) {
                $received .= yield;
            }
        };

        $response = new StandardResponse(new Filter([]), $writer());
        $response->send("test");
        $expected = "{proto} 200 \r\n__Aerys-Entity-Length: 4\r\n\r\ntest";
        $this->assertSame($expected, $received);
        $response->send("this should throw");
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot send: response already streaming
     */
    public function testSendThrowsIfResponseAlreadyStreaming() {
        $received = "";
        $writer = function() use (&$received) {
            while (true) {
                $received .= yield;
            }
        };

        $response = new StandardResponse(new Filter([]), $writer());
        $response->stream("test");
        $expected = "{proto} 200 \r\n__Aerys-Entity-Length: *\r\n\r\ntest";
        $this->assertSame($expected, $received);
        $response->send("this should throw");
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot stream: response already sent
     */
    public function testStreamThrowsIfResponseAlreadySent() {
        $received = "";
        $writer = function() use (&$received) {
            while (true) {
                $received .= yield;
            }
        };

        $response = new StandardResponse(new Filter([]), $writer());
        $response->send("test");
        $expected = "{proto} 200 \r\n__Aerys-Entity-Length: 4\r\n\r\ntest";
        $this->assertSame($expected, $received);
        $response->stream("this should throw");
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot stream: response already sent
     */
    public function testStreamThrowsIfResponseAlreadyEnded() {
        $received = "";
        $writer = function() use (&$received) {
            while (true) {
                $received .= yield;
            }
        };

        $response = new StandardResponse(new Filter([]), $writer());
        $response->end();
        $expected = "{proto} 200 \r\n__Aerys-Entity-Length: @\r\n\r\n";
        $this->assertSame($expected, $received);
        $response->stream("this should throw");
    }
    
    public function testMultiStream() {
        $received = "";
        $writer = function() use (&$received) {
            while (true) {
                $received .= yield;
            }
        };

        $response = new StandardResponse(new Filter([]), $writer());
        $response->stream("foo\n");
        $response->stream("bar\n");
        $response->stream("baz\n");
        $response->end("bat\n");
        $expected = "{proto} 200 \r\n__Aerys-Entity-Length: *\r\n\r\nfoo\nbar\nbaz\nbat\n";
        $this->assertSame($expected, $received);
    }
    
    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot flush: response already sent
     */
    public function testFlushThrowsIfResponseAlreadySent() {
        $received = "";
        $writer = function() use (&$received) {
            while (true) {
                $received .= yield;
            }
        };

        $response = new StandardResponse(new Filter([]), $writer());
        $response->end();
        $expected = "{proto} 200 \r\n__Aerys-Entity-Length: @\r\n\r\n";
        $this->assertSame($expected, $received);
        $response->flush();
    }
    
    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot flush: response output not started
     */
    public function testFlushThrowsIfResponseOutputNotStarted() {
        $received = "";
        $writer = function() use (&$received) {
            while (true) {
                $received .= yield;
            }
        };

        $response = new StandardResponse(new Filter([]), $writer());
        $response->flush();
    }
}
