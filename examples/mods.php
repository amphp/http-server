<?php

/**
 * examples/mods.php
 * 
 * @TODO Add some description here. To run this example:
 * 
 * $ php mods.php
 * 
 * Once the server has started, request http://127.0.0.1:1337/ in your browser or client of choice.
 */

use Aerys\Config\Configurator;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support_files/ExampleBeforeResponseMod.php'; // our example mod class

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

$myApp = function(array $asgiEnv) {
    return [200, '', [], '<html><body><h1>Hello, World.</h1></body></html>'];
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
                    30 => 10, // send a 429 if client has made > 10 requests in the past 30 seconds
                ]
            ],
        ]
    ]
];


$server = (new Configurator)->createServer($config);
$exampleMod = new ExampleBeforeResponseMod($server); // <-- class file included above!
$server->registerMod('*', $exampleMod); // register the mod for all virtual hosts (*)

// this would register the mod only for a specific host:
// $server->registerMod('mysite.com:80', $exampleMod);

$server->start(); // start the server

