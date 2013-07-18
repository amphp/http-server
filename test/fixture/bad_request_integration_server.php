<?php

require dirname(__DIR__) . '/bootstrap.php';

(new Aerys\Config\Bootstrapper)->createServer([
    'options' => [
        'maxHeaderBytes' => 512,
        'maxBodyBytes' => 1,
        'defaultContentType' => 'text/plain',
        'verbosity' => 0
    ],
    'server' => [
        'listenOn'    => '*:1500',
        'application' => function() { return [200, 'OK', [], "We shouldn't ever see this"]; }
    ]
])->start();
