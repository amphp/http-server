<?php

/**
 * examples/websocket_mod_echo.php
 * 
 * IMPORTANT NOTE ABOUT WEBSOCKETS:
 * --------------------------------
 * 
 * Note that if you actually want to see something happen with a websocket endpoint you need to
 * simultaneously serve an HTML resource referencing javascript that connects to that endpoint. If
 * you only serve a websocket endpoint by itself and try to open it in a browser you'll receive a
 * 426 Upgrade Required response. Websockets don't work that way. They aren't pages you can simply
 * open in a browser. For this reason the example code below serves static files from the specified
 * document root as the main application handler. One of those static files is a javascript file
 * that connects to the endpoint we've setup at `ws://127.0.0.1:1337/echo`.
 * 
 * DEMO:
 * -----
 * 
 * While an Aerys host can serve strictly ws:// or wss:// resources, it's often useful to layer 
 * endpoints on top of your regular application handler. To accomplish this we simply register the 
 * built-in websocket mod for the relevant host. Now the requests for the URI(s) specified by the
 * websocket mod will be caught and handled by the specified websocket endpoints instead of being
 * handled by the normal application callable.
 * 
 * In this example we serve static files from a specified document root for all requests but register
 * the websocket mod to intercept requests made to the /echo URI and process them using our
 * EchoEndpoint class.
 * 
 * Configuring a websocket endpoint using the websocket mod is exactly the same as doing so for a
 * host-wide websocket application handler. The only difference is that we place the config array
 * inside the ['mods' => ['websocket' => 'endpointUri' => [config]]] block.
 * 
 * HOW TO RUN THIS DEMO:
 * ---------------------
 * 
 * $ php examples/websocket_mod_echo.php
 * 
 * Once the server has started, request http://127.0.0.1:1337/ in your browser or client of choice.
 * If you open the address in multiple tabs or windows you'll see that your data is shared between
 * all connected clients.
 */

use Aerys\Config\Bootstrapper,
    Aerys\Handlers\DocRoot\DocRootLauncher;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/websocket/EchoEndpoint.php'; // <-- our websocket endpoint class

(new Bootstrapper)->createServer([[
    'listenOn'      => '*:1337',
    'application'   => new DocRootLauncher([
        'docRoot'   => __DIR__ . '/support/websocket/echo_docroot' // <-- serves our HTML + JS
    ]),
    'mods' => [
        'websocket' => [
            '/echo' => [
                'endpoint' => 'EchoEndpoint',   // <-- *required (Endpoint class name or instance)
                
                // --- OPTIONAL WEBSOCKET ENDPOINT CONFIG KEYS FOLLOW --- //
                
                'allowedOrigins'   => [],       // Empty array means any origin is allowed
                'maxFrameSize'     => 2097152,  // Maximum allowed frame size
                'maxMsgSize'       => 10485760, // Maximum allowed message size
                'heartbeatPeriod'  => 10,       // If greater than zero Aerys will auto-heartbeat
                'subprotocol'      => NULL      // Optional websocket subprotocol name
            ]
        ]
    ]
]])->start();
