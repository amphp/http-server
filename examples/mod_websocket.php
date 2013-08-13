<?php

use Aerys\Handlers\DocRoot\DocRootLauncher;

/**
 * While an Aerys host can serve strictly ws:// or wss:// resources via the WebsocketHandler, it's
 * often useful to layer websocket endpoints on top of your existing application. To accomplish this
 * we simply:
 * 
 * (1) Register the built-in websocket mod for the relevant host.
 * (2) Sit back and let the websocket mod intercept any requests for our websocket URIs instead of
 *     allowing them through to the hosts' primary application callable.
 * 
 * In this example we serve static files from a document root to provide the HTML and javascript
 * files needed to interface with the websocket endpoint. The websocket mod captures any requests
 * to the URI path "/echo" and treats them as websocket handshakes.
 */

require __DIR__ . '/support/websocket/EchoEndpoint.php'; // <-- Our websocket endpoint class

$config = [
    'my-websocket-app' => [
        'listenOn'      => '*:1338',
        'application'   => new DocRootLauncher([
            'docRoot'   => __DIR__ . '/support/websocket/mod_docroot' // <-- serves our HTML + JS
        ]),
        'mods' => [
            'websocket' => [
                '/echo' => [
                    'endpoint' => 'EchoEndpoint', // <-- Our websocket endpoint class
                ]
            ]
        ]
    ]
];
