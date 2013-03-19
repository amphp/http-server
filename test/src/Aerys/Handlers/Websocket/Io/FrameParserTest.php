<?php

use Aerys\Handlers\Websocket\Frame,
    Aerys\Handlers\Websocket\Io\FrameParser;

class FrameParserTest extends PHPUnit_Framework_TestCase {
    
    private function generateMaskingKey() {
        return pack('C*', mt_rand(1, 255), mt_rand(1, 255), mt_rand(1, 255), mt_rand(1, 255));
    }
    
    private function generateParsableStream(Frame $frame) {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $frame);
        rewind($stream);
        
        return $stream;
    }
    
    public function provideParseExpectations() {
        $returnArr = [];
        
        // 0 -------------------------------------------------------------------------------------->
        $fin = 1;
        $rsv = 0;
        $opcode = Frame::OP_TEXT;
        $payload = 'woot';
        $frame = new Frame($fin, $rsv, $opcode, $payload, $this->generateMaskingKey());
        
        $returnArr[] = [$frame];
        
        // 1 -------------------------------------------------------------------------------------->
        $fin = 1;
        $rsv = 0;
        $opcode = Frame::OP_BIN;
        $payload = pack('C', 'When in the chronicle of wasted time');
        $frame = new Frame($fin, $rsv, $opcode, $payload, $this->generateMaskingKey());
        
        $returnArr[] = [$frame];
        
        // 2 -------------------------------------------------------------------------------------->
        $fin = 1;
        $rsv = 0;
        $opcode = Frame::OP_PING;
        $payload = 'Yo my name is Humpty, pronounced with an "umpty"';
        $frame = new Frame($fin, $rsv, $opcode, $payload, $this->generateMaskingKey());
        
        $returnArr[] = [$frame];
        
        // 3 -------------------------------------------------------------------------------------->
        $fin = 1;
        $rsv = 0;
        $opcode = Frame::OP_PONG;
        $payload = '42';
        $frame = new Frame($fin, $rsv, $opcode, $payload, $this->generateMaskingKey());
        
        $returnArr[] = [$frame];
        
        // x -------------------------------------------------------------------------------------->
        
        return $returnArr;
    }
    
    /**
     * @dataProvider provideParseExpectations
     */
    public function testFrameParse(Frame $frame) {
        $input = $this->generateParsableStream($frame);
        $parser = new FrameParser($input);
        
        list($opcode, $length, $payload) = $parser->parse();
        if (is_resource($payload)) {
            $payload = stream_get_contents($payload);
        }
        
        $this->assertEquals($frame->getOpcode(), $opcode);
        $this->assertEquals($frame->getLength(), $length);
        $this->assertEquals($frame->getPayload(), $payload);
    }
    
    /**
     * @dataProvider provideParseExpectations
     */
    public function testMultiReadFrameParse(Frame $frame) {
        $input = $this->generateParsableStream($frame);
        $parser = new FrameParser($input);
        $parser->setGranularity(1);
        
        while (!$result = $parser->parse()) {
            continue;
        }
        
        list($opcode, $length, $payload) = $result;
        if (is_resource($payload)) {
            $payload = stream_get_contents($payload);
        }
        
        $this->assertEquals($frame->getOpcode(), $opcode);
        $this->assertEquals($frame->getLength(), $length);
        $this->assertEquals($frame->getPayload(), $payload);
    }
    
    public function testMultiFrameMessageParse() {
        $frames = [];
        
        $fin = 0;
        $rsv = 0;
        $opcode = Frame::OP_TEXT;
        $payload = 'Frame 1';
        $frames[] = new Frame($fin, $rsv, $opcode, $payload, $this->generateMaskingKey());
        
        $fin = 0;
        $rsv = 0;
        $opcode = Frame::OP_CONT;
        $payload = ' ';
        $frames[] = new Frame($fin, $rsv, $opcode, $payload, $this->generateMaskingKey());
        
        $fin = 1;
        $rsv = 0;
        $opcode = Frame::OP_TEXT;
        $payload = 'Frame 2';
        $frames[] = new Frame($fin, $rsv, $opcode, $payload, $this->generateMaskingKey());
        
        $expectedOpcode = $frames[0]->getOpcode();
        $expectedLength = 0;
        $expectedPayload = '';
        
        $stream = fopen('php://memory', 'r+');
        foreach ($frames as $frame) {
            fwrite($stream, $frame);
            $expectedLength += $frame->getLength();
            $expectedPayload .= $frame->getPayload();
        }
        rewind($stream);
        
        $parser = new FrameParser($stream);
        $parser->setGranularity(1);
        
        while (!$result = $parser->parse()) {
            continue;
        }
        
        list($opcode, $length, $payload) = $result;
        if (is_resource($payload)) {
            $payload = stream_get_contents($payload);
        }
        
        $this->assertEquals($expectedOpcode, $opcode);
        $this->assertEquals($expectedLength, $length);
        $this->assertEquals($expectedPayload, $payload);
    }
    
}



























