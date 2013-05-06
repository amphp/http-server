<?php

// To run, execute this script and request http://127.0.0.1:1337/ in your browser

/**
 * IMPORTANT
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
 */

use Aerys\Config\Configurator,
    Aerys\Config\ReverseProxyLauncher;

require dirname(__DIR__) . '/autoload.php';

(new Configurator)->createServer([[
    'listenOn'     => '*:80',
    'application'  => new ReverseProxyLauncher([
        'maxPendingRequests' => 1500, // OPTIONAL (defaults to 1500 if not specified)
        'backends' => [
            '127.0.0.1:1337',         // An array of backend server addresses
        ],
    ])
]])->start();

