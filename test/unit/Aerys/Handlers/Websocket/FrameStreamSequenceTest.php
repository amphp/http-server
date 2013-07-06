<?php

use Aerys\Handlers\Websocket\Frame,
    Aerys\Handlers\Websocket\FrameStreamSequence,
    Aerys\Handlers\Websocket\FrameStreamResource;

class FrameStreamSequenceTest extends PHPUnit_Framework_TestCase {
    
    function testIteration() {
        $body = 'zanzibar';
        $resource = fopen('data://text/plain;base64,' . base64_encode($body), 'r');
        $frameStreamRes = new FrameStreamResource(Frame::OP_TEXT, $resource);
        $frameStreamRes->setFrameSize(1);
        
        $frameStream = new FrameStreamSequence(Frame::OP_TEXT, $frameStreamRes);
        
        $data = '';
        foreach ($frameStream as $current) {
            $data .= $current;
        }
        
        $this->assertEquals($body, $data);
    }
    
}

