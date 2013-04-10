<?php

use Aerys\Writing\BodyWriterFactory,
    Aerys\Writing\ByteRangeBody,
    Aerys\Writing\MultiPartByteRangeBody;

class BodyWriterFactoryTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @dataProvider provideMakeExpectations
     */
    function testMake($destinationStream, $body, $protocol, $contentLength, $expectedType) {
        $bwf = new BodyWriterFactory;
        $bodyWriter = $bwf->make($destinationStream, $body, $protocol, $contentLength);
        $this->assertInstanceOf($expectedType, $bodyWriter);
    }
    
    /**
     * @dataProvider provideBadMakeExpectations
     * @expectedException DomainException
     */
    function testMakeThrowsOnInvalidInputParameters($destinationStream, $body, $protocol, $contentLength) {
        $bwf = new BodyWriterFactory;
        $bodyWriter = $bwf->make($destinationStream, $body, $protocol, $contentLength);
    }
    
    function provideMakeExpectations() {
        $return = [];
        
        $destinationStream = fopen('php://memory', 'r+');
        
        // 0 ---------------------------------------------------------------------------------------
        
        $body = 'some string';
        $protocol = '1.1';
        $contentLength = strlen($body);
        $expectedType = 'Aerys\Writing\StringBodyWriter';
        
        $return[] = [
            $destinationStream,
            $body,
            $protocol,
            $contentLength,
            $expectedType
        ];
        
        // 1 ---------------------------------------------------------------------------------------
        
        $body = fopen('php://memory', 'r+');
        $protocol = '1.1';
        $contentLength = 42;
        $expectedType = 'Aerys\Writing\ResourceBodyWriter';
        
        $return[] = [
            $destinationStream,
            $body,
            $protocol,
            $contentLength,
            $expectedType
        ];
        
        // 2 ---------------------------------------------------------------------------------------
        
        $body = new ByteRangeBody(fopen('php://memory', 'r+'), NULL, NULL);
        $protocol = '1.1';
        $contentLength = NULL;
        $expectedType = 'Aerys\Writing\ByteRangeBodyWriter';
        
        $return[] = [
            $destinationStream,
            $body,
            $protocol,
            $contentLength,
            $expectedType
        ];
        
        // 3 ---------------------------------------------------------------------------------------
        
        $body = new MultiPartByteRangeBody(NULL, [], NULL, NULL, NULL);
        $protocol = '1.1';
        $contentLength = NULL;
        $expectedType = 'Aerys\Writing\MultiPartByteRangeBodyWriter';
        
        $return[] = [
            $destinationStream,
            $body,
            $protocol,
            $contentLength,
            $expectedType
        ];
        
        // 4 ---------------------------------------------------------------------------------------
        
        $body = $this->getMock('Iterator');
        $protocol = '1.1';
        $contentLength = NULL;
        $expectedType = 'Aerys\Writing\ChunkedIteratorBodyWriter';
        
        $return[] = [
            $destinationStream,
            $body,
            $protocol,
            $contentLength,
            $expectedType
        ];
        
        // 5 ---------------------------------------------------------------------------------------
        
        $body = $this->getMock('Iterator');
        $protocol = '1.0';
        $contentLength = NULL;
        $expectedType = 'Aerys\Writing\IteratorBodyWriter';
        
        $return[] = [
            $destinationStream,
            $body,
            $protocol,
            $contentLength,
            $expectedType
        ];
        
        // x ---------------------------------------------------------------------------------------
        
        return $return;
    }
    
    function provideBadMakeExpectations() {
        $return = [];
        
        $destinationStream = fopen('php://memory', 'r+');
        
        // 0 ---------------------------------------------------------------------------------------
        
        $body = new StdClass;
        $protocol = '1.1';
        $contentLength = 42;
        
        $return[] = [
            $destinationStream,
            $body,
            $protocol,
            $contentLength
        ];
        
        // 1 ---------------------------------------------------------------------------------------
        
        $body = 'string';
        $protocol = '1.1';
        $contentLength = NULL;
        
        $return[] = [
            $destinationStream,
            $body,
            $protocol,
            $contentLength
        ];
        
        // 2 ---------------------------------------------------------------------------------------
        
        $body = fopen('php://memory', 'r+');
        $protocol = '1.1';
        $contentLength = NULL;
        
        $return[] = [
            $destinationStream,
            $body,
            $protocol,
            $contentLength
        ];
        
        // x ---------------------------------------------------------------------------------------
        
        return $return;
    }
    
}

