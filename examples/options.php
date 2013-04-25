<?php

/**
 * examples/options.php
 * 
 * Need moar options? Assign HTTP server options in the 'options' config array. To run
 * this example:
 * 
 * $ php options.php
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
    'options' => [
        'logErrorsTo'           => 'php://stderr',
        'maxConnections'        => 2500,
        'maxRequests'           => 150,
        'keepAliveTimeout'      => 5,
        'disableKeepAlive'      => FALSE,
        'maxStartLineSize'      => 2048,
        'maxHeadersSize'        => 8192,
        'maxEntityBodySize'     => 10485760,
        'bodySwapSize'          => 2097152,
        'defaultContentType'    => 'text/html', // StaticFiles handler defaults to text/plain
        'defaultCharset'        => 'utf-8',
        'sendServerToken'       => FALSE,
        'normalizeMethodCase'   => TRUE,
        'autoReasonPhrase'      => TRUE,
        'defaultHost'           => NULL,
        'requireBodyLength'     => FALSE,
        'allowedMethods'        => ['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'TRACE', 'DELETE'],
        'socketSoLinger'        => NULL, // Requires PHP ext/sockets
    ],
    
    'reactor' => NULL, // Optionally specify your own Amp\Reactor instance
    
    'mods' => [
        // Any mods specified here are global to all hosts. A mod of the same type specified
        // in a specific host container will override a matching global mod.
    ],
    
    // --- ALL KEYS NOT NAMED "options," "mods," or "reactor" ARE CONSIDERED HOST CONTAINERS ---
    
    'myHost'   => [
        'listenOn'      => '*:1337',
        'application'   => $myApp
    ]
];


(new Configurator)->createServer($config)->start();

