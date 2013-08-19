<?php

namespace Aerys\Test\Handlers\Websocket;

use Aerys\Handlers\Websocket\Frame,
    Aerys\Handlers\Websocket\FrameParser;

class FrameParserTest extends \PHPUnit_Framework_TestCase {
    
    private function generateMaskingKey() {
        return pack('C*', mt_rand(1, 255), mt_rand(1, 255), mt_rand(1, 255), mt_rand(1, 255));
    }
    
    private function generateParsableStream(Frame $frame) {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $frame);
        rewind($stream);
        
        return $stream;
    }
    
    /**
     * @dataProvider provideParseExpectations
     */
    public function testFrameParse(Frame $frame) {
        list($opcode, $payload, $length) = (new FrameParser)->parse($frame);
        
        if (is_resource($payload)) {
            $payload = stream_get_contents($payload);
        }
        
        $this->assertEquals($frame->getOpcode(), $opcode);
        $this->assertEquals($frame->getLength(), $length);
        $this->assertEquals($frame->getPayload(), $payload);
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
        $payload = 'When in the chronicle of wasted time';
        $frame = new Frame($fin, $rsv, $opcode, $payload, $this->generateMaskingKey());
        
        $returnArr[] = [$frame];
        
        // 2 -------------------------------------------------------------------------------------->
        $fin = 1;
        $rsv = 0;
        $opcode = Frame::OP_PONG;
        $payload = '42';
        $frame = new Frame($fin, $rsv, $opcode, $payload, $this->generateMaskingKey());
        
        $returnArr[] = [$frame];
        
        // 3 -------------------------------------------------------------------------------------->
        $fin = 1;
        $rsv = 0;
        $opcode = Frame::OP_TEXT;
        $payload = str_repeat('x', 200);
        $frame = new Frame($fin, $rsv, $opcode, $payload, $this->generateMaskingKey());
        
        $returnArr[] = [$frame];
        
        // 4 -------------------------------------------------------------------------------------->
        $fin = 1;
        $rsv = 0;
        $opcode = Frame::OP_TEXT;
        $payload = str_repeat('x', 65536);
        $frame = new Frame($fin, $rsv, $opcode, $payload, $this->generateMaskingKey());
        
        $returnArr[] = [$frame];
        
        // x -------------------------------------------------------------------------------------->
        
        return $returnArr;
    }
    
    /**
     * @dataProvider provideParseExpectations
     */
    public function testMultiReadFrameParse(Frame $frame) {
        $parser = new FrameParser;
        
        $rawFrameStr = $frame->__toString();
        
        for ($i=0; $i<strlen($rawFrameStr); $i++) {
            $result = $parser->parse($rawFrameStr[$i]);
        }
        
        list($opcode, $payload, $length) = $result;
        
        if (is_resource($payload)) {
            $payload = stream_get_contents($payload);
        }
        
        $this->assertEquals($frame->getOpcode(), $opcode);
        $this->assertEquals($frame->getLength(), $length);
        $this->assertEquals($frame->getPayload(), $payload);
    }
    
    public function testMultiFrameMessageParse() {
        $frames = [];
        
        $frames[] = new Frame(
            $fin = 0,
            $rsv = 0,
            $opcode = Frame::OP_TEXT,
            $payload = 'Frame 1',
            $this->generateMaskingKey()
        );
        
        $frames[] = new Frame(
            $fin = 0,
            $rsv = 0,
            $opcode = Frame::OP_CONT,
            $payload = ' ',
            $this->generateMaskingKey()
        );
        
        $frames[] = new Frame(
            $fin = 1,
            $rsv = 0,
            $opcode = Frame::OP_TEXT,
            $payload = 'Frame 2',
            $this->generateMaskingKey()
        );
        
        $expectedOpcode = $frames[0]->getOpcode();
        $expectedLength = 0;
        $expectedPayload = '';
        
        $rawFrameData = '';
        foreach ($frames as $frame) {
            $expectedLength += $frame->getLength();
            $expectedPayload .= $frame->getPayload();
            $rawFrameData .= $frame->__toString();
        }
        
        $parser = new FrameParser;
        
        for ($i=0; $i<strlen($rawFrameData); $i++) {
            $result = $parser->parse($rawFrameData[$i]);
        }
        
        list($opcode, $payload, $length) = $result;
        
        if (is_resource($payload)) {
            $payload = stream_get_contents($payload);
        }
        
        $this->assertEquals($expectedOpcode, $opcode);
        $this->assertEquals($expectedLength, $length);
        $this->assertEquals($expectedPayload, $payload);
    }
    
}

