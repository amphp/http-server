<?php

namespace Aerys\Test\Writing;

use Aerys\Writing\WriterFactory,
    Aerys\Writing\ByteRangeBody,
    Aerys\Writing\MultiPartByteRangeBody;

class WriterFactoryTest extends \PHPUnit_Framework_TestCase {
    
    /**
     * @dataProvider provideMakeExpectations
     */
    function testMake($destinationStream, $body, $protocol, $expectedType) {
        $factory = new WriterFactory;
        $headers = 'test';
        $bodyWriter = $factory->make($destinationStream, $headers, $body, $protocol);
        $this->assertInstanceOf($expectedType, $bodyWriter);
    }
    
    /**
     * @dataProvider provideBadMakeExpectations
     * @expectedException DomainException
     */
    function testMakeThrowsOnInvalidInputParameters($destinationStream, $body, $protocol) {
        $factory = new WriterFactory;
        $writer = $factory->make($destinationStream, 'headers', $body, $protocol);
    }
    
    function provideMakeExpectations() {
        $return = [];
        
        $destinationStream = fopen('php://memory', 'r+');
        
        // 0 ---------------------------------------------------------------------------------------
        
        $body = 'some string';
        $protocol = '1.1';
        $expectedType = 'Aerys\Writing\Writer';
        
        $return[] = [
            $destinationStream,
            $body,
            $protocol,
            $expectedType
        ];
        
        // 1 ---------------------------------------------------------------------------------------
        
        $body = fopen('php://memory', 'r+');
        $protocol = '1.1';
        $expectedType = 'Aerys\Writing\StreamWriter';
        
        $return[] = [
            $destinationStream,
            $body,
            $protocol,
            $expectedType
        ];
        
        // 2 ---------------------------------------------------------------------------------------
        
        $body = new ByteRangeBody(fopen('php://memory', 'r+'), NULL, NULL);
        $protocol = '1.1';
        $expectedType = 'Aerys\Writing\ByteRangeWriter';
        
        $return[] = [
            $destinationStream,
            $body,
            $protocol,
            $expectedType
        ];
        
        // 3 ---------------------------------------------------------------------------------------
        
        $body = new MultiPartByteRangeBody(NULL, [], NULL, NULL, NULL);
        $protocol = '1.1';
        $expectedType = 'Aerys\Writing\MultiPartByteRangeWriter';
        
        $return[] = [
            $destinationStream,
            $body,
            $protocol,
            $expectedType
        ];
        
        // x ---------------------------------------------------------------------------------------
        
        return $return;
    }
    
    function provideBadMakeExpectations() {
        $return = [];
        
        $destinationStream = fopen('php://memory', 'r+');
        
        // 0 ---------------------------------------------------------------------------------------
        
        $body = new \StdClass;
        $protocol = '1.1';
        
        $return[] = [
            $destinationStream,
            $body,
            $protocol
        ];
        
        // x ---------------------------------------------------------------------------------------
        
        return $return;
    }
    
}

