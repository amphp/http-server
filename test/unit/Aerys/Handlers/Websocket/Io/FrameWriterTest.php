<?php

use Aerys\Handlers\Websocket\Frame,
    Aerys\Handlers\Websocket\Io\FrameWriter;

class FrameWriterTest extends PHPUnit_Framework_TestCase {
    
    public function provideWritableFrames() {
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
    
    /**
     * @dataProvider provideWritableFrames
     */
    public function testFrameWrite(Frame $frame) {
        $outputStream = fopen('php://memory', 'w+');
        $writer = new FrameWriter($outputStream);
        
        $writer->enqueue($frame);
        $this->assertTrue($writer->canWrite());
        $this->assertEquals($frame, $writer->write());
        $this->assertFalse($writer->canWrite());
        rewind($outputStream);
        $this->assertEquals($frame->__toString(), stream_get_contents($outputStream));
        
    }
    
    /**
     * @dataProvider provideWritableFrames
     */
    public function testMultiPassFrameWrite(Frame $frame) {
        $outputStream = fopen('php://memory', 'w+');
        $writer = new FrameWriter($outputStream);
        $writer->setGranularity(1);
        
        $writer->enqueue($frame);
        $this->assertTrue($writer->canWrite());
        
        while (!$frame = $writer->write()) {
            continue;
        }
        
        $this->assertFalse($writer->canWrite());
        rewind($outputStream);
        
        $this->assertEquals($frame->__toString(), stream_get_contents($outputStream));
    }
    
    public function testMultiFrameMessageWrite() {
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
        
        $outputStream = fopen('php://memory', 'w+');
        $writer = new FrameWriter($outputStream);
        $writer->setGranularity(1);
        
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
}



























