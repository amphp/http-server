<?php

/**
 * examples/websocket_tls.php
 * 
 * Using TLS-encrypted websocket connections is as simple as adding the TLS declaration to the
 * host on which the wss:// endpoint is served.
 * 
 * To run the example, execute this script and request https://127.0.0.1:1443/ in your browser.
 */

use Aerys\Config\DocRootLauncher, Aerys\Config\Configurator;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/ExampleChatEndpoint.php'; // <-- example endpoint class file

(new Configurator)->createServer([[
    'listenOn' => '*:1443',
    'application' => new DocRootLauncher([
        'docRoot' => __DIR__ . '/support/websocket_tls_root'
    ]),
    'tls' => [
        'local_cert' => __DIR__ . '/support/localhost_cert.pem',
        'passphrase' => '42 is not a legitimate passphrase'
    ],
    'mods' => [
        'websocket' => [
            '/chat' => new ExampleChatEndpoint
        ],
]])->start();

