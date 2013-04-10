<?php

use Aerys\Writing\StringBodyWriter;

class StringBodyWriterTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @expectedException Aerys\Writing\ResourceWriteException
     */
    function testWriteThrowsExceptionOnResourceWriteFailure() {
        $destination = 'will fail because this should be a resource';
        $writer = new StringBodyWriter($destination, 'test', 4);
        $writer->write();
    }
    
    function testWrite() {
        $destination = fopen('php://memory', 'r+');
        $body = 'test';
        $contentLength = strlen($body);
        $writer = new StringBodyWriter($destination, 'test', $contentLength);
        $writer->setGranularity(1);
        
        while (!$writer->write());
        
        rewind($destination);
        
        $this->assertEquals($body, stream_get_contents($destination));
    }
    
}

