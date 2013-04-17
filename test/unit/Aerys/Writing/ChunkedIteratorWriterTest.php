<?php

use Aerys\Writing\ChunkedIteratorWriter;

class ChunkedIteratorWriterTest extends PHPUnit_Framework_TestCase {
    
    function testWrite() {
        $destination = fopen('php://memory', 'r+');
        $headers = 'headers';
        $body = new ChunkedIteratorWriterTestIteratorStub;
        $expectedWrite = $headers .
            "1\r\n" .
            "t\r\n" .
            "1\r\n" . 
            "e\r\n" .
            "1\r\n" .
            "s\r\n" .
            "1\r\n" .
            "t\r\n" .
            "0\r\n\r\n";
        
        $writer = new ChunkedIteratorWriter($destination, $headers, $body);
        $writer->setGranularity(1);
        
        while (!$writer->write());
        
        rewind($destination);
        
        $this->assertEquals($expectedWrite, stream_get_contents($destination));
    }
    
}

class ChunkedIteratorWriterTestIteratorStub implements Iterator {
    
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
