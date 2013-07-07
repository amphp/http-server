<?php

use Aerys\Handlers\Websocket\Frame,
    Aerys\Handlers\Websocket\FrameStreamFactory;

class FrameStreamFactoryTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @dataProvider provideBadStreams
     * @expectedException InvalidArgumentException
     */
    function testInvokeThrowsOnInvalidStreamDataType($badData) {
        $factory = new FrameStreamFactory;
        $factory->__invoke(Frame::OP_TEXT, $badData);
    }
    
    function provideBadStreams() {
        return [
            [new StdClass],
            [array()],
            [NULL],
            [$this->getMock('Iterator')]
        ];
    }
    
    /**
     * @dataProvider provideScalarData
     */
    function testInvokeReturnsFrameStreamStringOnScalar($scalarData) {
        $factory = new FrameStreamFactory;
        $stream = $factory->__invoke(Frame::OP_TEXT, $scalarData);
        $this->assertInstanceOf('Aerys\Handlers\Websocket\FrameStreamString', $stream);
    }
    
    function provideScalarData() {
        return [
            ['test'],
            [42],
            [TRUE]
        ];
    }
    
}
