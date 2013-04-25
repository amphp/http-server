<?php

/**
 * examples/hello_world.php
 * 
 * This is the most basic HTTP/1.1 server you can create; it returns the same basic response for
 * every request it receives. To run:
 * 
 * $ php hello_world.php
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
    $body = '<html><body><h1>Hello, World.</h1></body></html>';
    
    return [$status, $reason, $headers, $body];
};

(new Configurator)->createServer([[
    'listenOn'      => '127.0.0.1:1337',
    'application'   => $myApp
]])->start();

