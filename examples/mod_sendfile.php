<?php

/**
 * examples/mod_sendfile.php
 * 
 * @TODO Add explanation
 */

use Aerys\Config\Bootstrapper;

require dirname(__DIR__) . '/autoload.php';

class MyApp {
    
    function __invoke(array $asgiEnv, $requestId) {
        // Do some dynamic validation or whatever based on the request environment here. Once you
        // know if/what you want to send, respond using a standard ASGI response specifying the
        // `X-Sendfile` header. Paths in this header are resolved relative to the docRoot setting
        // in the mod configuration array.
        
        if ($asgiEnv['REQUEST_URI'] === '/example') {
            $response = [
                $status = 200,
                $reason = '',
                $headers = [
                    'X-Sendfile: example.txt' // leading slash in path makes no difference
                ],
                $body = NULL
            ];
        } else {
            $response = [200, 'OK', [], 'hello world'];
        }
        
        return $response;
    }
}

(new Bootstrapper)->createServer([[
    'listenOn'      => '*:1337',
    'application'   => 'MyApp',
    'mods' => [
        'log' => [
            'php://stdout' => 'common'
        ],
        'send-file' => [
            'docRoot' => __DIR__ . '/support/sendfile', // *required (path to your static files)
        ],
    ]
]])->start();

