<?php

namespace Aerys\Test\Handlers\Broker;

use Aerys\Responders\Websocket\Frame,
    Aerys\Responders\Websocket\FramePriorityQueue;

class FramePriorityQueueTest extends \PHPUnit_Framework_TestCase {
    
    function testCount() {
        $queue = new FramePriorityQueue;
        
        $frame = new Frame($isFin = 0, $rsv = 0b000, $op = Frame::OP_TEXT, $payload='first');
        $queue->insert($frame);
        
        $frame = new Frame($isFin = 0, $rsv = 0b000, $op = Frame::OP_CONT, $payload='second');
        $queue->insert($frame);
        
        $frame = new Frame($isFin = 1, $rsv = 0b000, $op = Frame::OP_CONT, $payload='third');
        $queue->insert($frame);
        
        $this->assertEquals(3, count($queue));
    }
    
    function testExtractPrioritizesControlFrames() {
        $queue = new FramePriorityQueue;
        
        $frame = new Frame($isFin = 0, $rsv = 0b000, $op = Frame::OP_TEXT, $payload='first');
        $queue->insert($frame);
        
        $frame = new Frame($isFin = 0, $rsv = 0b000, $op = Frame::OP_CONT, $payload='second');
        $queue->insert($frame);
        
        $pongFrame = new Frame($isFin = 1, $rsv = 0b000, $op = Frame::OP_PONG, $payload='PONG');
        $queue->insert($pongFrame);
        
        $frame = new Frame($isFin = 1, $rsv = 0b000, $op = Frame::OP_CONT, $payload='third');
        $queue->insert($frame);
        
        $extractedFrame = $queue->extract();
        
        $this->assertSame($pongFrame, $extractedFrame);
    }
    
}

