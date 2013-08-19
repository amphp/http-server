<?php

namespace Aerys\Test\Writing;

use Aerys\Writing\Writer;

class WriterTest extends \PHPUnit_Framework_TestCase {
    
    /**
     * @expectedException Aerys\Writing\ResourceException
     */
    function testWriteThrowsExceptionOnResourceWriteFailure() {
        $destination = 'will fail because this should be a resource';
        $writer = new Writer($destination, 'test');
        $writer->write();
    }
    
    function testWrite() {
        $destination = fopen('php://memory', 'r+');
        $body = 'test';
        $writer = new Writer($destination, $body);
        $writer->setGranularity(1);
        
        while (!$writer->write());
        
        rewind($destination);
        
        $this->assertEquals($body, stream_get_contents($destination));
    }
    
}

