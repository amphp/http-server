<?php

namespace Aerys\Test\Handlers\Broker;

use Aerys\Responders\Websocket\Frame,
    Aerys\Responders\Websocket\FrameWriter;

class FrameWriterTest extends \PHPUnit_Framework_TestCase {
    
    /**
     * @dataProvider provideWritableFrames
     */
    function testFrameWrite(Frame $frame) {
        $outputStream = fopen('php://memory', 'w+');
        
        // For some reason PHP segfaults when the filter is applied on this test #phpWTF
        //stream_filter_append($outputStream, "single_byte_write", STREAM_FILTER_WRITE);
        
        $writer = new FrameWriter($outputStream);
        
        $writer->enqueue($frame);
        $this->assertTrue($writer->canWrite());
        $this->assertEquals($frame, $writer->write());
        $this->assertFalse($writer->canWrite());
        $this->assertNull($writer->write());
        
        rewind($outputStream);
        $this->assertEquals($frame->__toString(), stream_get_contents($outputStream));
        
    }
    
    /**
     * @expectedException Aerys\Responders\Websocket\FrameWriteException
     */
    function testWriteThrowsExceptionIfDestinationStreamGoesAway() {
        $destination = fopen('php://memory', 'w+');
        
        $writer = new FrameWriter($destination);
        $frame = new Frame($fin=1, $rsv=0, $opcode=Frame::OP_TEXT, $payload='test');
        $writer->enqueue($frame);
        
        $reflObject = new \ReflectionObject($writer);
        $reflProperty = $reflObject->getProperty('destination');
        $reflProperty->setAccessible(TRUE);
        $reflProperty->setValue($writer, 'not a resource anymore');
        
        $writer->write();
    }
    
    /**
     * @dataProvider provideWritableFrames
     */
    function testMultiPassFrameWrite(Frame $frame) {
        $outputStream = fopen('php://memory', 'w+');
        
        // Only write data one byte at a time to test partial write logic
        stream_filter_append($outputStream, "single_byte_write", STREAM_FILTER_WRITE);
        
        $writer = new FrameWriter($outputStream);
        
        
        $writer->enqueue($frame);
        $this->assertTrue($writer->canWrite());
        
        while (!$frame = $writer->write()) {
            continue;
        }
        $this->assertFalse($writer->canWrite());
        rewind($outputStream);
        
        $this->assertEquals($frame->__toString(), stream_get_contents($outputStream));
    }
    
    function testMultiFrameMessageWrite() {
        $outputStream = fopen('php://memory', 'w+');
        
        // Only write data one byte at a time to test partial write logic
        stream_filter_append($outputStream, "single_byte_write", STREAM_FILTER_WRITE);
        
        
        $frames = [];
        
        $fin = 0;
        $rsv = 0;
        $opcode = Frame::OP_TEXT;
        $payload = 'frame1';
        $frames[] = new Frame($fin, $rsv, $opcode, $payload);
        
        $fin = 0;
        $rsv = 0;
        $opcode = Frame::OP_TEXT;
        $payload = 'frame2';
        $frames[] = new Frame($fin, $rsv, $opcode, $payload);
        
        $fin = 1;
        $rsv = 0;
        $opcode = Frame::OP_TEXT;
        $payload = 'frame3';
        $frames[] = new Frame($fin, $rsv, $opcode, $payload);
        
        $writer = new FrameWriter($outputStream);
        
        $expectedResult = '';
        foreach ($frames as $frame) {
            $writer->enqueue($frame);
            $expectedResult .= $frame;
        }
        
        while ($writer->canWrite()) {
            $writer->write();
        }
        
        rewind($outputStream);
        $actualResult = stream_get_contents($outputStream);
        
        $this->assertEquals($expectedResult, $actualResult);
    }
    
    function provideWritableFrames() {
        $returnArr = [];
        
        // 0 -------------------------------------------------------------------------------------->
        $fin = 1;
        $rsv = 0;
        $opcode = Frame::OP_TEXT;
        $payload = 'woot';
        $frame = new Frame($fin, $rsv, $opcode, $payload);
        
        $returnArr[] = [$frame];
        
        // 1 -------------------------------------------------------------------------------------->
        $fin = 1;
        $rsv = 0;
        $opcode = Frame::OP_BIN;
        $payload = pack('n', 'woot');
        $frame = new Frame($fin, $rsv, $opcode, $payload);
        
        $returnArr[] = [$frame];
        
        // 2 -------------------------------------------------------------------------------------->
        $fin = 1;
        $rsv = 0;
        $opcode = Frame::OP_PING;
        $payload = uniqid();
        $frame = new Frame($fin, $rsv, $opcode, $payload);
        
        $returnArr[] = [$frame];
        
        // x -------------------------------------------------------------------------------------->
        
        return $returnArr;
    }
    
}



























