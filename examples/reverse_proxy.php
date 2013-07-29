<?php

/**
 * IMPORTANT:
 * ----------
 * 
 * The ReverseProxy functionality relies on backend servers to correctly implement two very
 * important HTTP/1.1 features:
 * 
 * (1) HTTP/1.1 request pipelining as outlined in RFC 2616.
 * (2) HTTP/1.1 persistent connections (Connection: keep-alive)
 * 
 * As long as you aren't using a garbage backend server you shouldn't have any problems ...
 * 
 * Suggested settings for apache backend servers are:
 * 
 * KeepAlive On
 * MaxKeepAliveRequests 0 # (no limit)
 * KeepAliveTimeout 86400
 * 
 * If using an Aerys backend server, the following options should be set in the backend config
 * array to allow long-lived keep-alive connections to the front-facing reverse proxy:
 * 
 * $config = [
 *     'options' => [
 *         'maxRequests'      => 0,
 *         'keepAliveTimeout' => 0,
 *     ]
 * ];
 * 
 * HOW TO RUN THIS DEMO:
 * ---------------------
 * 
 * $ php examples/reverse_proxy.php
 * 
 * Once the server has started, request http://127.0.0.1/ in your browser or client of choice.
 * You'll see that the request is proxied and responded to by the backend server running on :1337.
 * Note that for this demo to work you must have an http backend server running on port 1337. Also,
 * in *nix environments you will likely need sudo or root privileges to start a server on port 80.
 */

use Aerys\Config\Bootstrapper,
    Aerys\Handlers\ReverseProxy\ReverseProxyLauncher;

require dirname(__DIR__) . '/autoload.php';

date_default_timezone_set('UTC');

(new Bootstrapper)->createServer([[
    'listenOn'     => '*:80',
    'name'         => 'localhost',
    'application'  => new ReverseProxyLauncher([
        'backends' => [
            '127.0.0.1:1337',                   // REQUIRED: An array of backend server addresses
        ],
        'proxyPassHeaders' => [                 // OPTIONAL: Add/override headers sent to backends
            'Host'            => '$host',       // Any literal value or substitution variable
            'X-Forwarded-For' => '$remoteAddr', // Available vars: [$host, $serverName, $serverAddr, $serverPort, $remoteAddr]
            'X-Real-Ip'       => '$serverAddr'
        ],
        'maxPendingRequests'  => 1500,            // OPTIONAL: defaults to 1500 if not specified
        'debug' => TRUE,
        'debugColors' => TRUE
    ])
]])->start();










