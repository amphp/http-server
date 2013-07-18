<?php

// To run, execute this script and request http://127.0.0.1:1337/ in your browser

use Aerys\Config\Bootstrapper;

require dirname(__DIR__) . '/autoload.php';

$myApp = function(array $asgiEnv) {
    $body = '<html><body><h1>Hello, World.</h1></body></html>';
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

(new Bootstrapper)->createServer([[
    'listenOn'      => '*:1337',
    'application'   => $myApp
]])->start();

