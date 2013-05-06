<?php

/**
 * examples/streaming_iterator_body.php
 * 
 * One of the niftier aspects of the ASGI specification is the allowance of PHP Iterator instances
 * as response bodies. If presented with an Iterator body Aerys will stream its contents to the
 * client until Iterator::valid() returns FALSE. This works both for HTTP/1.0 and HTTP/1.1 clients.
 * 
 * To run the example, execute this script and request http://127.0.0.1:1337/ in your browser
 */

use Aerys\Config\Configurator;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/ExampleIteratorBody.php';

$myApp = function(array $asgiEnv) {
    return [
        $status = 200,
        $reason = 'OK',
        $headers = [],
        $body = new ExampleIteratorBody
    ];
};

(new Configurator)->createServer([[
    'listenOn'      => '*:1337',
    'application'   => $myApp
]])->start();

