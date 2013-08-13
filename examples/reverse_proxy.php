<?php

use Aerys\Handlers\ReverseProxy\ReverseProxyLauncher;

/**
 * Reverse proxying allows you to insert aerys between users and backend application servers. The
 * server can then act as a middleware staging point to modify and react to information flowing in
 * either direction for things like caching, app-specific analysis and layering websocket endpoints.
 * 
 * The ReverseProxy functionality expects backend servers to correctly implement two very
 * important HTTP/1.1 features:
 * 
 * (1) HTTP/1.1 request pipelining as outlined in RFC 2616.
 * (2) HTTP/1.1 persistent connections (Connection: keep-alive)
 * 
 * As long as you aren't using a garbage backend server you shouldn't have any problems. Suggested
 * settings for apache backend servers are:
 * 
 * KeepAlive On
 * MaxKeepAliveRequests 0 # (no limit)
 * KeepAliveTimeout 86400
 * 
 * Like all aerys servers, if you'll need to specify a host block for each named host you'd like to
 * which you'd like to proxy backend requests.
 */

$myProxyApp = new ReverseProxyLauncher([
    'backends' => [
        '127.0.0.1:1337', // *required: An array of backend server addresses
    ],
    // --- ALL OTHER SETTINGS ARE OPTIONAL --- //
    'proxyPassHeaders' => [                 // Add/override headers sent to backends
        'Host'            => '$host',       // Any literal value or substitution variable
        'X-Forwarded-For' => '$remoteAddr', // Available: [$host, $serverName, $serverAddr, $serverPort, $remoteAddr]
        'X-Real-Ip'       => '$serverAddr'
    ],
    'maxPendingRequests'  => 1500,          // Defaults to 1500 if not specified
    'debug'               => TRUE,          // Dump debugging output to the console
    'debugColors'         => TRUE           // Color the debug output (won't work in windows)
]);

$config = [
    'my-proxied-app' => [
        'listenOn'     => '*:80',
        'name'         => 'localhost',
        'application'  => $myProxyApp
    ]
];
