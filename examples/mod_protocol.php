<?php

use Aerys\Handlers\DocRoot\DocRootLauncher;

/**
 * This example uses ModProtocol to layer a custom socket protocol onto the *SAME* listening socket
 * as our HTTP server -and- our websocket server. As a result we can share access to the same chat
 * data via websocket clients in the browser as well as any client connecting via the custom
 * protocol specified in our handler.
 */

require __DIR__ . '/support/protocol/WebsocketEndpoint.php';
require __DIR__ . '/support/protocol/LineFeedProtocolHandler.php';
require __DIR__ . '/support/protocol/ChatMediator.php';

$config = [
    'aerys.definitions' => [
        'shares' => [
            'ChatMediator'
        ]
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
];
