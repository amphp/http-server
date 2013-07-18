<?php

// To run, execute this script and request http://127.0.0.1:1337/ in your browser

use Aerys\Config\Bootstrapper;

require dirname(__DIR__) . '/autoload.php';

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
        'maxHeaderBytes'        => 8192,
        'maxBodyBytes'          => 10485760,
        'defaultContentType'    => 'text/html',
        'defaultTextCharset'    => 'utf-8',
        'sendServerToken'       => FALSE,
        'normalizeMethodCase'   => TRUE,
        'autoReasonPhrase'      => TRUE,
        'requireBodyLength'     => TRUE,
        'allowedMethods'        => ['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'TRACE', 'DELETE'],
        'socketSoLingerZero'    => FALSE, // Requires PHP's ext/sockets extension
        'verbosity'             => 1,     // Server::SILENT (0), Server::QUIET (1), Server::LOUD (2)
        'defaultHost'           => NULL   // Must match a registered Host ID, e.g. mysite.com:80 or *:1337
    ],
    
    // --- ALL KEYS NOT NAMED "options" or "dependencies" ARE CONSIDERED HOST CONTAINERS ---
    
    'myHost'   => [
        'listenOn' => '*:1337',
        'application' => $myApp
    ]
];

(new Bootstrapper)->createServer($config)->start();
