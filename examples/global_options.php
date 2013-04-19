<?php

/**
 * examples/global_options.php
 * 
 * Need moar options? Assign HTTP server options in the 'globals' => 'opts' config array. To run
 * this example:
 * 
 * $ php global_options.php
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

$config = [
    'globals' => [ // <-- the key name MUST be "globals"
        
        'opts' => [
            'logErrorsTo'           => 'php://stderr',
            'maxConnections'        => 1000,
            'maxRequestsPerSession' => 100,
            'keepAliveTimeout'      => 10,
            'maxStartLineSize'      => 2048,
            'maxHeadersSize'        => 8192,
            'maxEntityBodySize'     => 2097152,
            'tempEntityDir'         => NULL,
            'defaultContentType'    => 'text/html',
            'defaultCharset'        => 'utf-8',
            'disableKeepAlive'      => FALSE,
            'sendServerToken'       => FALSE,
            'handleBeforeBody'      => FALSE,
            'normalizeMethodCase'   => TRUE,
            'autoReasonPhrase'      => TRUE,
            'defaultHost'           => NULL,
            'allowedMethods'        => ['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'TRACE', 'DELETE'],
            'soLinger'              => NULL,
        ],
        
        'mods' => [
            // Any mods specified here are global to all hosts. A mod of the same type specified
            // in a specific host container will override a matching global mod.
        ]
    ],
    
    // --- ALL OTHER KEYS ARE CONSIDERED HOST CONTAINERS ---
    
    'myHost'   => [
        'listenOn'      => '127.0.0.1:1337',
        'application'   => $myApp
    ]
];


(new Configurator)->createServer($config)->listen();

