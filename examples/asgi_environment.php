<?php

// To run, execute this script and request http://127.0.0.1:1337/ in your browser

use Aerys\Config\Configurator;

require dirname(__DIR__) . '/autoload.php';

$myApp = function(array $asgiEnv) {
    $body = '<html><body><h1>Hello, world.</h1>';
    $body.= '<h3>Your request environment is ...</h3>';
    $body.= '<pre>' . print_r($asgiEnv, TRUE) . '</pre>';
    $body.= '</body></html>';
    
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

(new Configurator)->createServer([[
    'listenOn'      => '*:1337',
    'application'   => $myApp
]])->start();

