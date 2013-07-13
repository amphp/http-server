<?php

/**
 * examples/websocket_mod_echo_tls.php
 *
 * NOTE:
 * -----
 * This example only demonstrates the necessary options for TLS encryption on a given host
 * application in conjunction with a websocket mod. For an in-depth look at TLS encryption or
 * Websocket endpoints individually please view the other relevant demos.
 * 
 * DEMO:
 * -----
 * 
 * Using TLS-encrypted websockets no different from encrypting any other Aerys host. Simply add your
 * TLS declaration to the host configuration block on which the secure wss:// endpoint is served.
 * 
 * HOW TO RUN THIS DEMO:
 * ---------------------
 * 
 * To run the example, execute this script and request https://127.0.0.1:1443/ in your browser.
 * Make sure to use "https://" <--- with an "s" on the end when entering the demo's address!
 */

use Aerys\Config\DocRootLauncher, Aerys\Config\Configurator;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/websocket/EchoEndpoint.php'; // <-- our websocket endpoint class

(new Configurator)->createServer([[
    'listenOn'      => '*:1443',
    'application'   => new DocRootLauncher([
        'docRoot'   => __DIR__ . '/support/websocket/echo_docroot' // <-- serves our HTML + JS
    ]),
    'tls' => [
        'local_cert' => __DIR__ . '/support/localhost_cert.pem',
        'passphrase' => '42 is not a legitimate passphrase'
    ],
    'mods' => [
        'websocket' => [
            '/echo' => [
                'endpoint' => 'EchoEndpoint'
            ]
        ]
    ]
]])->start();
