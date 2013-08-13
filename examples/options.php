<?php

/**
 * There is only one reserved name key in an Aerys configuration array: "options" ... You may
 * specify any value you like to internally identify individual host blocks in your config array but
 * the "options" key is ALWAYS interpreted as an array of key => value server settings. Use this
 * array to define server-wide configuration directives.
 */

$myApp = function(array $asgiEnv) {
    $body = '<html><body><h1>Hello, world.</h1></body></html>';
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$config = [
    'my-site' => [
        'listenOn' => '*:1337',
        'application' => $myApp
    ],
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
        'alwaysAddDateHeader'   => FALSE,
        'autoReasonPhrase'      => TRUE,
        'requireBodyLength'     => TRUE,
        'allowedMethods'        => ['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'TRACE', 'DELETE'],
        'socketSoLingerZero'    => FALSE, // Requires PHP's ext/sockets extension
        'verbosity'             => 1,     // Server::SILENT (0), Server::QUIET (1), Server::LOUD (2)
        'defaultHost'           => NULL   // Must match a registered Host ID, e.g. mysite.com:80 or *:1337
    ]
];
