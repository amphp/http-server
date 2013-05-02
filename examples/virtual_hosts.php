<?php

/**
 * examples/virtual_hosts.php
 * 
 * 
 * 
 * !!!!!!!! IMPORTANT !!!!!!
 * 
 * This example utilizes Aerys's name-based virtual hosting capability to serve the front-facing
 * static files from one host (myhost) and the backend websocket host on a separate host (websockets.myhost).
 * This example will not work unless you specify these names in your hosts file. In linux this means
 * editing `/etc/hosts` so that it looks similar to this:
 * 
 *     127.0.0.1     localhost aerys static.aerys
 * 
 * In windows environments the line looks the same but the hosts file is located at:
 * 
 *     %systemroot%\system32\drivers\etc\hosts
 * 
 * Aerys allows the specification of multiple virtual hosts in the same server instance. Each host
 * specifies its own application handler and mods.
 * 
 * An Aerys server may specify as many virtual host names as needed. For example, an application
 * could serve static files from one host using the built-in static file handler, websocket endpoints
 * from another host using the included websocket functionality and do both while serving a traditional
 * PHP application on yet another hostname.
 * 
 * To run this example:
 * 
 * $ php virtual_hosts.php
 * 
 * Once the server has started, request http://aerys:1337/ in your browser or client of choice.
 */

use Aerys\Config\StaticFilesApp,
    Aerys\Config\Configurator;

require dirname(__DIR__) . '/autoload.php';

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

$myApp = function(array $asgiEnv) {
    if ($asgiEnv['REQUEST_URI'] == '/favicon.ico') {
        $response = [404, 'Not Found', $headers = [], '<h1>404 Not Found</h1>'];
    } else {
        $status = 200;
        $reason = 'OK';
        $body = '<html><body style="font-family: Sans-Serif;"><h1>Hello, world.</h1><hr/>';
        $body.= '<img src="http://static.aerys:1337/allofthethings.gif" width="480" height="335" /><hr/><br/>';
        $body.= '</body></html>';
        $response = [$status, $reason, $headers = [], $body];
    }
    
    return $response;
};

$config = [
    'host.dynamic' => [
        'listenOn'      => '127.0.0.1:1337',
        'name'          => 'aerys', // <--- ADD NAME TO YOUR HOSTS FILE OR THE EXAMPLE WON'T WORK
        'application'   => $myApp
    ],
    'host.static' => [
        'listenOn'      => '127.0.0.1:1337',
        'name'          => 'static.aerys', // <--- ADD NAME TO YOUR HOSTS FILE OR THE EXAMPLE WON'T WORK
        'application'   => new StaticFilesApp([
            'docRoot'   => __DIR__ . '/support_files/file_server_root'
        ])
    ]
];

(new Configurator)->createServer($config)->start();

