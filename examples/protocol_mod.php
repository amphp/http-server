<?php

/**
 * examples/protocol_mod.php
 * 
 * This example uses ModProtocol to layer a custom socket protocol onto the same listening socket
 * as our HTTP server. Custom socket protocols are implemented on the same port as the HTTP server.
 * 
 * @TODO Add more explanation for this example.
 */

use Auryn\Provider,
    Aerys\Config\Bootstrapper,
    Aerys\Handlers\DocRoot\DocRootLauncher;

require dirname(__DIR__) . '/autoload.php';

require __DIR__ . '/support/protocol/WebsocketEndpoint.php';
require __DIR__ . '/support/protocol/LineFeedProtocolHandler.php';
require __DIR__ . '/support/protocol/ChatMediator.php';

$injector = new Provider;
$injector->share('ChatMediator'); // <-- ensure both websocket + protocol handlers get the same chat mediator

(new Bootstrapper($injector))->createServer([
    'options' => [
        'keepAliveTimeout' => 90
    ],
    'my-server' => [
        'listenOn' => '*:80',
        'application'   => new DocRootLauncher([
            'docRoot'   => __DIR__ . '/support/protocol/docroot' // <-- serves HTML+JS for websocket /echo endpoint
        ]),
        'mods' => [
            'websocket' => [
                '/echo' => [
                    'endpoint' => 'WebsocketEndpoint' // <-- class implementing Aerys\Handlers\Websocket\Endpoint
                ]
            ],
            'protocol' => [
                'handlers' => [
                    'LineFeedProtocolHandler' // <-- class implementing Aerys\Mods\Protocol\ProtocolHandler
                ]
            ]
        ]
    ]
])->start();
