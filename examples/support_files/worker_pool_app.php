<?php

/**
 * worker_pool_app.php
 * 
 * This file provides the handler used in the `worker_pool.php` server example. The front controller
 * file for worker pool applications must specify an `aerys()` front controller function. This 
 * function's only job is to return the callable ASGI application handler to respond to client 
 * requests. The front controller function is invoked only once -- when a worker process is first
 * started. This allows applications to benefit from faster load times for requests down the line
 * because the bootstrap phase of the application isn't needed for each new requests.
 * 
 * WORKER POOL APPLICATION RESPONSE BODIES
 * 
 * While worker pool applications can assign string and Iterator entity body values in their ASGI
 * response arrays, it's important to note that a PHP resource MUST NOT be used. This is because
 * resources cannot be serialized for transport between processes.
 * 
 * STREAMING ENTITY BODY
 * 
 * This example demonstrates the use of a custom Iterator to stream the response body to the client.
 * Aerys will continue streaming chunks of data as part of the same response as long as the Iterator
 * passed in the ASGI body position is valid. The obvious use case is situations in which you want
 * to send headers to the client but the determination of the body may take some time (think slow
 * database queries). In such situations you can create a custom Iterator implementation to stream
 * the result and allow faster load times for clients who can start parsing the request before it
 * completes.
 */

$myApp = new MyApp;

function main(array $asgiEnv) {
    global $myApp;
    return $myApp->onRequest($asgiEnv);
}

class MyApp {
    function onRequest(array $asgiEnv) {
        $status = 200;
        $reason = 'OK';
        $headers = [];
        
        //$body = new ExampleIteratorBody;
        $body = 'Hello, world.';
        
        return [$status, $reason, $headers, $body];
    }
}

class ExampleIteratorBody implements Iterator {
    
    private $position = 0;
    private $parts = [
        '<html><body style="font-family: Sans-Serif;"><h1>Hello, world.</h1>',
        '<h3>Streaming Iterator chunk 1</h3>',
        NULL, // <-- waiting for more data
        '<h4>Streaming Iterator chunk 2</h4>',
        NULL, // <-- waiting for more data
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
        return isset($this->parts[$this->position]) || array_key_exists($this->position, $this->parts);
    }
    
}
