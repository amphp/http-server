<?php

require dirname(__DIR__) . '/bootstrap.php';

(new Aerys\Config\Configurator)->createServer([
    'options' => [
        'maxHeaderBytes' => 512,
        'maxBodyBytes' => 1,
        'defaultContentType' => 'text/plain'
    ],
    'server' => [
        'listenOn'    => '*:1500',
        'application' => function() { return [200, 'OK', [], "We shouldn't ever see this"]; }
    ]
])->start();
