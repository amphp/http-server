<?php

/**
 * examples/websocket_host_echo.php
 * 
 * NOTE:
 * -----
 * 
 * This example demonstrates how to serve *only* websocket endpoints from a given host. If you wish
 * to layer websocket endpoints on top of the same host as a dynamic or static file app, please
 * examine the `websocket_mod_*` examples.
 * 
 * DEMO:
 * -----
 * 
 * If you actually want to see something happen with a websocket endpoint you need to simultaneously
 * serve an HTML resource referencing javascript that connects to that endpoint. If you only serve a
 * websocket endpoint by itself and try to open it in a browser you'll receive a 426 Upgrade Required
 * response. Websockets don't work that way. They aren't pages you can simply open in a browser.
 * 
 * Nevertheless, you may have an application in which the workload is distributed to different
 * server machines or processes on the same box. The code below demonstrates how to serve *only*
 * websocket endpoints from a given host. Each endpoint must specify its request URI path as key
 * and an array of values specifying options for the endpoint. The 'endpoint' key must either be
 * an instance of the websocket endpoint interface or the string name of such the endpoint class
 * to be used. Endpoint classes have their constructor dependencies automatically provisioned by
 * the Auryn dependency injection container. The `WebsocketLauncher` will accept as many endpoint
 * URI configuration blocks as you care to assign.
 * 
 * HOW TO RUN THIS DEMO:
 * ---------------------
 * 
 * $ php examples/websocket_host_echo.php
 * 
 */

use Aerys\Config\Configurator,
    Aerys\Config\WebsocketLauncher;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/websocket/EchoEndpoint.php'; // <-- our websocket endpoint class

(new Configurator)->createServer([[
    'listenOn'      => '*:1337',
    'application'   => new WebsocketLauncher([
        '/echo' => [
            'endpoint' => 'EchoEndpoint',   // <-- *required (Endpoint class name or instance)
            
            // --- OPTIONAL WEBSOCKET ENDPOINT CONFIG KEYS FOLLOW --- //
            
            'allowedOrigins'   => [],       // Empty array means any origin is allowed
            'maxFrameSize'     => 2097152,  // Maximum allowed frame size
            'maxMsgSize'       => 10485760, // Maximum allowed message size
            'heartbeatPeriod'  => 10,       // If greater than zero Aerys will auto-heartbeat
            'subprotocol'      => NULL      // Optional websocket subprotocol name
        ]
    ])
]])->start();
