<?php

namespace Aerys\Test\Handlers\Broker;

use Aerys\Responders\Websocket\Frame;

class FrameTest extends \PHPUnit_Framework_TestCase {
    
    function testIsFin() {
        $frame = new Frame($isFin = 1, $rsv = 0b001, $op = Frame::OP_TEXT, $payload='test');
        $this->assertTrue($frame->isFin());
        
        $frame = new Frame($isFin = 0, $rsv = 0b001, $op = Frame::OP_TEXT, $payload='test');
        $this->assertFalse($frame->isFin());
    }
    
    function testHasRsv1() {
        $frame = new Frame($isFin = 0, $rsv = 0b001, $op = Frame::OP_TEXT, $payload='test');
        $this->assertTrue($frame->hasRsv1());
        
        $frame = new Frame($isFin = 0, $rsv = 0b100, $op = Frame::OP_TEXT, $payload='test');
        $this->assertFalse($frame->hasRsv1());
    }
    
    function testHasRsv2() {
        $frame = new Frame($isFin = 0, $rsv = 0b010, $op = Frame::OP_TEXT, $payload='test');
        $this->assertTrue($frame->hasRsv2());
        
        $frame = new Frame($isFin = 0, $rsv = 0b101, $op = Frame::OP_TEXT, $payload='test');
        $this->assertFalse($frame->hasRsv2());
    }
    
    function testHasRsv3() {
        $frame = new Frame($isFin = 0, $rsv = 0b110, $op = Frame::OP_TEXT, $payload='test');
        $this->assertTrue($frame->hasRsv3());
        
        $frame = new Frame($isFin = 0, $rsv = 0b011, $op = Frame::OP_TEXT, $payload='test');
        $this->assertFalse($frame->hasRsv3());
    }
    
    function testGetMaskingKey() {
        $frame = new Frame($isFin = 0, $rsv = 0b110, $op = Frame::OP_TEXT, $payload='test');
        $this->assertNull($frame->getMaskingKey());
        
        $frame = new Frame($isFin = 0, $rsv = 0b011, $op = Frame::OP_TEXT, $payload='test', $mask='mymask');
        $this->assertEquals('mymask', $frame->getMaskingKey());
    }
    
    function testGetPayload() {
        $frame = new Frame($isFin = 0, $rsv = 0b011, $op = Frame::OP_TEXT, $payload='test');
        $this->assertEquals('test', $frame->getPayload());
    }
    
    function testGetOpcode() {
        $frame = new Frame($isFin = 0, $rsv = 0b011, $op = Frame::OP_TEXT, $payload='test');
        $this->assertEquals(Frame::OP_TEXT, $frame->getOpcode());
    }
    
    function testGetLength() {
        $frame = new Frame($isFin = 0, $rsv = 0b011, $op = Frame::OP_TEXT, $payload='test');
        $this->assertEquals(strlen($frame->getPayload()), $frame->getLength());
    }
    
    function testToString() {
        $frame = new Frame($isFin = 0, $rsv = 0b011, $op = Frame::OP_TEXT, $payload='test');
        $frame = (string) $frame;
        
        $payload = str_repeat('x', 32768);
        $frame = new Frame($isFin = 0, $rsv = 0b011, $op = Frame::OP_TEXT, $payload);
        $frame = (string) $frame;
        
        $payload = str_repeat('x', 80000);
        $frame = new Frame($isFin = 0, $rsv = 0b011, $op = Frame::OP_TEXT, $payload);
        $frame = (string) $frame;
    }
    
}

