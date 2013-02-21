<?php

/**
 * examples/hello_world.php
 * 
 * This is the most basic HTTP/1.1 server you can create; it returns a "hello world" message with
 * a 200 status code for every request it receives. To run:
 * 
 * $ php hello_world.php
 * 
 * Once the server has started, request http://127.0.0.1:1337/ in your browser or client of choice.
 */

require dirname(__DIR__) . '/autoload.php';

date_default_timezone_set('GMT');

$handler = function(array $asgiEnv) {
    $status = 200;
    $reason = 'OK';
    $headers = [];
    
    $body = '<html><body><h1>Hello, world.</h1>';
    $body.= '<h3>Your request environment is ...</h3>';
    $body.= '<pre>' . print_r($asgiEnv, TRUE) . '</pre>';
    $body.= '</body></html>';
    
    return [$status, $reason, $headers, $body];
};

(new Aerys\Http\HttpServerFactory)->createServer([[
    'listen'  => '127.0.0.1:1337',
    'handler' => $handler
]])->listen();

