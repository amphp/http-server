<?php

/**
 * examples/websocket_vhost.php
 * 
 * !!!!!!!! IMPORTANT !!!!!!
 * 
 * This example utilizes Aerys's name-based virtual hosting capability to serve the front-facing
 * static files from one host (myhost) and the backend websocket host on a separate host (websockets.myhost).
 * This example will not work unless you specify these names in your hosts file. In linux this means
 * editing `/etc/hosts` so that it looks similar to this:
 * 
 *     127.0.0.1     localhost myhost websockets.myhost
 * 
 * In windows environments the line looks the same but the hosts file is located at:
 * 
 *     %systemroot%\system32\drivers\etc\hosts
 * 
 * If you want/need to serve websocket endpoints on the same hostname as standard web content please
 * see the mod_websocket.php example file.
 * 
 * To run this example:
 * 
 * $ php websocket_vhost.php
 * 
 * Once the server has started, request http://myhost:1337/ in your browser or client of choice.
 * If you open the address in multiple tabs you'll see that your data is passed back and forth via
 * the websocket application!
 */

use Aerys\Config\WebsocketApp,
    Aerys\Config\DocRootLauncher,
    Aerys\Config\Configurator;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/ExampleChatEndpoint.php'; // <-- example endpoint class file

$config = [
    'my-primary-domain' => [
        'listenOn'      => '*:1337',
        'name'          => 'myhost', // <--- ADD NAME TO YOUR SYSTEM HOSTS FILE!
        'application'   => new DocRootLauncher([
            'docRoot'   => __DIR__ . '/support/websocket_vhost_root'
        ]),
    ],
    'my-websocket-subdomain' => [
        'listenOn'      => '*:1337',
        'name'          => 'websockets.myhost', // <--- ADD NAME TO YOUR SYSTEM HOSTS FILE!
        'application'   => new WebsocketLauncher([
            '/chat'     => new ExampleChatEndpoint // Included in the require statement at the top
        ])
    ]
];

(new Configurator)->createServer($config)->start();

