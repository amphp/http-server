<?php

namespace Aerys\Test\Handlers\Websocket;

use Aerys\Responders\Websocket\Frame,
    Aerys\Responders\Websocket\FrameStreamResource;

class FrameStreamResourceTest extends \PHPUnit_Framework_TestCase {
    
    function testIteration() {
        $body = 'zanzibar';
        $resource = fopen('data://text/plain;base64,' . base64_encode($body), 'r');
        $frameStream = new FrameStreamResource(Frame::OP_TEXT, $resource);
        $frameStream->setFrameSize(1);
        
        $data = '';
        foreach ($frameStream as $current) {
            $data .= $current;
        }
        
        $this->assertEquals($body, $data);
    }
    
    function testCountRetainsKey() {
        $body = 'zanzibar';
        $resource = fopen('data://text/plain;base64,' . base64_encode($body), 'r');
        $frameStream = new FrameStreamResource(Frame::OP_TEXT, $resource);
        $frameStream->setFrameSize(1);
        
        $frameStream->current();
        $frameStream->current();
        $frameStream->next();
        $frameStream->current();
        $frameStream->next();
        
        $this->assertEquals(2, $frameStream->key());
        $this->assertEquals(strlen($body), $frameStream->count());
        $this->assertEquals(2, $frameStream->key());
    }
    
}

