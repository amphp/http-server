<?php

/**
 * examples/asgi_environment.php
 * 
 * All Aerys requests are relayed to the application using the standard ASGI environment array. This
 * example responds to every request with a "hello world" message and a printed summary of this set
 * of request environment variables. To run:
 * 
 * $ php asgi_environment.php
 * 
 * Once the server has started, request http://127.0.0.1:1337/ in your browser or client of choice.
 */

use Aerys\Config\Configurator;

require dirname(__DIR__) . '/autoload.php';

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

$myApp = function(array $asgiEnv) {
    $status = 200;
    $reason = 'OK';
    $headers = [];
    
    $body = '<html><body><h1>Hello, world.</h1>';
    $body.= '<h3>Your request environment is ...</h3>';
    $body.= '<pre>' . print_r($asgiEnv, TRUE) . '</pre>';
    $body.= '</body></html>';
    
    return [$status, $reason, $headers, $body];
};

(new Configurator)->createServer([[
    'listenOn'      => '127.0.0.1:1337',
    'application'   => $myApp
]])->start();

