<?php

namespace Aerys\Test\Writing;

use Aerys\Writing\IteratorWriter;

class IteratorWriterTest extends \PHPUnit_Framework_TestCase {
    
    function testWrite() {
        $destination = fopen('php://memory', 'r+');
        $headers = 'headers';
        $body = new IteratorWriterTestIteratorStub;
        $expectedBody = $headers . 'test';
        
        $contentLength = strlen($expectedBody);
        $writer = new IteratorWriter($destination, $headers, $body);
        $writer->setGranularity(1);
        
        while (!$writer->write());
        
        rewind($destination);
        
        $this->assertEquals($expectedBody, stream_get_contents($destination));
    }
    
}

class IteratorWriterTestIteratorStub implements \Iterator {
    
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
        return isset($this->parts[$this->position]) ? $this->parts[$this->position] : NULL;
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
