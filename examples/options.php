<?php

/**
 * Aerys reserves certain configuration keys for internal use:
 * 
 * - "aerys.options"
 * - "aerys.beforeStart"
 * - "aerys.definitions"
 * 
 * All other keys are considered host blocks. Applications should avoid prefixing their host block
 * keys with "aerys.*" to avoid potential conflicts with future reserved keys. Otherwise,
 * applications may specify any value they like to internally differentiate individual hosts in the
 * config array.
 * 
 * We can assign server-wide configuration directives using the "aerys.options" key as demonstrated
 * below.
 */

$myApp = function(array $asgiEnv) {
    $body = '<html><body><h1>Hello, world.</h1></body></html>';
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$config = [
    'my-site' => [ // <-- All unreserved keys are treated as host definitions
        'listenOn' => '*:1337',
        'application' => $myApp
    ],
    'aerys.options' => [ // <-- Reserved key used to set server options
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
    ]
];
