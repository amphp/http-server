<?php

class ExampleIteratorBody implements Iterator {
    
    private $position = 0;
    private $parts = [
        '<html><body style="font-family: Sans-Serif;"><h1>Hello, world.</h1>',
        '<h3>Streaming Iterator chunk 1</h3>',
        NULL, // <-- Return NULL if awaiting more data
        '<h4>Streaming Iterator chunk 2</h4>',
        NULL, // <-- Return NULL if awaiting more data
        '<h5>Streaming Iterator chunk 3</h5>',
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

