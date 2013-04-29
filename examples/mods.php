<?php

// To run, execute this script and request http://127.0.0.1:1337/ in your browser

use Aerys\Config\Configurator;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support_files/ExampleBeforeResponseMod.php'; // our example mod class

$myApp = function(array $asgiEnv) {
    $body = '<html><body><h1>Hello, World.</h1></body></html>';
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$config = [
    'mods' => [
        // Any mods specified here are global to all hosts. A mod of the same type specified
        // in a specific host container will override a matching global mod.
    ],
    
    'myHost'   => [
        'listenOn'      => '*:1337',
        'application'   => $myApp,
        
        'mods' => [
            'log'   =>  [
                'logs' => [
                    'php://stdout' => 'common',
                ]
            ],
            'limit' => [
                'limits' => [
                    30 => 10
                ]
            ],
        ]
    ]
];


$server = (new Configurator)->createServer($config);
$exampleMod = new ExampleBeforeResponseMod($server); // <-- class file included above!
$server->registerMod('*', $exampleMod); // register the custom mod for all virtual hosts (*)

// register the mod for a specific host:
// $server->registerMod('mysite.com:80', $exampleMod);

// register the mod for all hosts on port 80:
// $server->registerMod('*:80', $exampleMod);

$server->start(); // start the server

