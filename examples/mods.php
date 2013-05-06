<?php

// To run, execute this script and request http://127.0.0.1:1337/ in your browser

use Aerys\Config\Configurator;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/ExampleBeforeResponseMod.php'; // our example mod class

$myApp = function(array $asgiEnv) {
    $body = '<html><body><h1>Hello, World.</h1></body></html>';
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$server = (new Configurator)->createServer([[
    'listenOn'      => '*:1337',
    'application'   => $myApp,
    'mods' => [
        'log'   =>  [
            'php://stdout' => 'common'
        ],
        'limit' => [
            'limits' => [
                30 => 10 // limit IPs to 10 requests every 30 seconds
            ]
        ]
    ]
]]);

$exampleMod = new ExampleBeforeResponseMod($server); // <-- class file included above!

// register the custom mod for all virtual hosts (*)
$server->registerMod('*', $exampleMod);

// register mod for a specific host:
// $server->registerMod('mysite.com:80', $exampleMod);

// register mod for all hosts on port 80:
// $server->registerMod('*:80', $exampleMod);

$server->start(); // start the server

