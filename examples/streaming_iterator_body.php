<?php

/**
 * examples/streaming_iterator_body.php
 * 
 * One of the niftier aspects of the ASGI specification is the allowance of PHP Iterator instances
 * as response bodies. If presented with an Iterator body Aerys will stream its contents to the
 * client until Iterator::valid() returns FALSE. This works both for HTTP/1.0 and HTTP/1.1 clients.
 * 
 * Of course, using an Iterator if you know the response content ahead of time is pointless. This
 * feature is ideal for wrapping the results of an asynchronous task so that the headers can be
 * dispatched to the client immediately instead of waiting for your application to generate the
 * entire response body. The body can then be streamed to the client as it's generated.
 * 
 * To run the example, execute this script and request http://127.0.0.1:1337/ in your browser
 */

use Aerys\Config\Bootstrapper;

require dirname(__DIR__) . '/autoload.php';

class ExampleIteratorBody implements Iterator {
    
    private $position = 0;
    private $parts = [
        '<html><body style="font-family: Sans-Serif;"><h1>Hello, world.</h1>',
        '<h3>Streaming Iterator chunk 1</h3>',
        NULL, // <-- Return NULL if awaiting more data
        '<h4>Streaming Iterator chunk 2</h4>',
        '<h5>Streaming Iterator chunk 3</h5>',
    ];

    function rewind() { $this->position = 0; }
    function current() { return $this->parts[$this->position]; }
    function key() { return $this->position; }
    function next() { $this->position++; }
    function valid() { return array_key_exists($this->position, $this->parts); }
}


$myApp = function(array $asgiEnv) {
    return [
        $status = 200,
        $reason = 'OK',
        $headers = [],
        $body = new ExampleIteratorBody
    ];
};

(new Bootstrapper)->createServer([[
    'listenOn'      => '*:1337',
    'application'   => $myApp
]])->start();

