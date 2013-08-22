<?php

namespace Aerys\Test\Handlers\Websocket;

use Aerys\Responders\Websocket\Frame, 
    Aerys\Responders\Websocket\FrameStreamString;

class FrameStreamTest extends \PHPUnit_Framework_TestCase {
    
    /**
     * @dataProvider provideBadOpcodes
     * @expectedException DomainException
     */
    function testConstructorThrowsOnBadOpcode($badOpcode) {
        $frameStream = new FrameStreamString($badOpcode, 'test');
    }
    
    function provideBadOpcodes() {
        return [
            [Frame::OP_CLOSE],
            [Frame::OP_PING],
            [Frame::OP_PONG],
            ['BAD'],
            [new \StdClass],
            [array()]
        ];
    }
    
    /**
     * @dataProvider provideFrameSizeExpectations
     */
    function testFrameSizeAccessors($toSet, $expected) {
        $frameStream = new FrameStreamString(Frame::OP_TEXT, 'test');
        $frameStream->setFrameSize($toSet);
        $this->assertEquals($expected, $frameStream->getFrameSize());
    }
    
    function provideFrameSizeExpectations() {
        return [
            [-42, 0],
            [32768, 32768],
            [-1, 0],
            [0, 0],
        ];
    }
    
    function testOpcodeAccessors() {
        $frameStream = new FrameStreamString(Frame::OP_TEXT, 'test');
        $this->assertTrue($frameStream->isText());
        $this->assertFalse($frameStream->isBinary());
        $this->assertEquals(Frame::OP_TEXT, $frameStream->getOpcode());
    }
}
    
