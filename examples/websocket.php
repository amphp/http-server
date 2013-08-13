<?php

use Aerys\Handlers\Websocket\WebsocketLauncher,
    Aerys\Handlers\DocRoot\DocRootLauncher;

/**
 * **NOTE:** This example demonstrates how to serve websocket endpoints from a given host. If you
 * wish to layer websocket endpoints on top of another host please check the `examples/mods`
 * directory for the appropriate file.
 * 
 * On websockets ...
 * 
 * If you actually want to see something happen with a websocket endpoint you need to simultaneously
 * serve an HTML resource referencing javascript that connects to that endpoint. If you only serve a
 * websocket endpoint by itself and try to open it in a browser you'll receive a 426 Upgrade Required
 * response. Websockets don't work that way. They aren't pages you can simply open in a browser.
 * 
 * As a result, this example creates two separate hosts:
 * 
 * - http://aerys will host the static HTML/JS files we need to interface with our websockets
 * - http://ws.aerys will host the websocket endpoint at the /echo URI path specified below
 * 
 * In order for the example to work correctly you'll need to modify your hosts file to point these
 * two domain names to your computer. In *nix this is done in the `/etc/hosts` file. In windows
 * environments this file is located at `%systemroot%\system32\drivers\etc\hosts`. In this file
 * make sure you're pointing these domain names at your local IP:
 * 
 * 127.0.0.1     aerys  ws.aerys
 * 
 * The WebsocketLauncher accepts a simple associative array matching URI path keys to the relevant
 * websocket configuration settings as demonstrated below. The "endpoint" key in this array must 
 * either be an instance of the websocket endpoint interface or the string name of such an endpoint
 * class.
 * 
 * Note that you may specify *multiple* websocket endpoint configurations for a single host in any
 * given launcher array. In this example we only specify the one endpoint at "ws://ws.aerys/echo"
 * 
 * To run this server:
 * 
 * $ php aerys.php -c="/path/to/websockets.php"
 * 
 * Once started you should be able to access the application at http://aerys in  your browser.
 */

require __DIR__ . '/support/websocket/EchoEndpoint.php'; // <-- Our websocket endpoint class

$config = [
    'my-static-files'   => [
        'listenOn'      => '*:80',
        'name'          => 'aerys',
        'application'   => new DocRootLauncher([
            'docRoot'   => __DIR__ . '/support/websocket/docroot'
        ])
    ],
    'my-websocket-host' => [
        'listenOn'      => '*:80',
        'name'          => 'ws.aerys',
        'application'   => new WebsocketLauncher([
            '/echo'     => [
                'endpoint' => 'EchoEndpoint',   // <-- *required (Endpoint class name or instance)
                
                // --- OPTIONAL ENDPOINT CONFIG KEYS FOLLOW --- //
                
                'allowedOrigins'   => [],       // Empty array means any origin is allowed
                'maxFrameSize'     => 2097152,  // Maximum allowed frame size
                'maxMsgSize'       => 10485760, // Maximum allowed message size
                'heartbeatPeriod'  => 10,       // If greater than zero Aerys will auto-heartbeat
                'subprotocol'      => NULL,     // Optional websocket subprotocol name
                'validateUtf8Text' => TRUE      // Should text frames be validated for UTF-8?
            ]
        ])
    ]
];
