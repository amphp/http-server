<?php

use Aerys\Writing\ByteRangeBodyWriter,
    Aerys\Writing\ByteRangeBody;

class ByteRangeBodyWriterTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @expectedException Aerys\Writing\ResourceWriteException
     */
    function testWriteThrowsExceptionOnResourceWriteFailure() {
        $destination = 'should fail because this is not a resource';
        $body = new ByteRangeBody($resource = fopen('php://memory', 'r+'), $startPos = NULL, $endPos = NULL);
        
        $writer = new ByteRangeBodyWriter($destination, $body);
        $writer->write();
    }
    
    function testWrite() {
        $destination = fopen('php://memory', 'r+');
        $bodyResource = fopen('php://memory', 'r+');
        fwrite($bodyResource, 'test');
        rewind($bodyResource);
        
        $body = new ByteRangeBody($bodyResource, $startPos = 0, $endPos = 3);
        $expectedBody = 'tes'; // <-- the "t" is purposely omitted
        
        $writer = new ByteRangeBodyWriter($destination, $body);
        $writer->setGranularity(1);
        
        while (!$writer->write());
        
        rewind($destination);
        
        $this->assertEquals($expectedBody, stream_get_contents($destination));
    }
    
}
