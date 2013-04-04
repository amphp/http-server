<?php

/**
 * examples/websockets.php
 * 
 * While an Aerys host can serve strictly ws:// or wss:// resources, it's often useful to layer 
 * endpoints on top of your regular application handler. To accomplish this we simply register the 
 * built-in websocket mod for the relevant host. Now the requests for the URI(s) specified by the
 * websocket mod will be caught and handled by the specified websocket endpoints instead of being
 * handled by the normal application callable.
 * 
 * To run:
 * 
 * $ php websockets.php
 * 
 * Once the server has started, request http://127.0.0.1:1337/ in your browser or client of choice.
 * If you open the address in multiple tabs you'll see that your data is passed back and forth via
 * the websocket application!
 */

use Aerys\Config\WebsocketApp,
    Aerys\Config\StaticFilesApp,
    Aerys\Config\Configurator;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support_files/WsExampleChatEndpoint.php';

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

(new Configurator)->createServer([[
    'listenOn'      => '127.0.0.1:1337',
    'application'   => new StaticFilesApp([
        'docRoot'   => __DIR__ . '/support_files/mod_websocket_root'
    ]),
    'mods' => [
        'websocket' => [
            '/chat' => new WsExampleChatEndpoint
        ]
    ]
]])->listen();

