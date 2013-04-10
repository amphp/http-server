<?php

use Aerys\Writing\ChunkedIteratorBodyWriter;

class ChunkedIteratorBodyWriterTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @expectedException Aerys\Writing\ResourceWriteException
     */
    function testWriteThrowsExceptionOnResourceWriteFailure() {
        $destination = 'should fail because this is not a resource';
        $body = new ChunkedIteratorBodyWriterTestIteratorStub;
        
        $writer = new ChunkedIteratorBodyWriter($destination, $body);
        $writer->write();
    }
    
    function testWrite() {
        $destination = fopen('php://memory', 'r+');
        $body = new ChunkedIteratorBodyWriterTestIteratorStub;
        $expectedBody = '' .
            "1\r\n" .
            "t\r\n" .
            "1\r\n" . 
            "e\r\n" .
            "1\r\n" .
            "s\r\n" .
            "1\r\n" .
            "t\r\n" .
            "0\r\n\r\n";
        
        $contentLength = strlen($expectedBody);
        $writer = new ChunkedIteratorBodyWriter($destination, $body);
        $writer->setGranularity(1);
        
        while (!$writer->write());
        
        rewind($destination);
        
        $this->assertEquals($expectedBody, stream_get_contents($destination));
    }
    
}

class ChunkedIteratorBodyWriterTestIteratorStub implements Iterator {
    
    private $position = 0;
    private $parts = [
        't',
        'e',
        NULL, // <-- waiting for more data
        's',
        NULL, // <-- waiting for more data
        't',
    ];

    function rewind() {
        $this->position = 0;
    }

    function current() {
        return $this->parts[$this->position];
    }

    function key() {
        return $this->position;
    }

    function next() {
        $this->position++;
    }

    function valid() {
        return array_key_exists($this->position, $this->parts);
    }
    
}
