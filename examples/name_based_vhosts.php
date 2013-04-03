<?php

/**
 * examples/name_based_vhosts.php
 * 
 * Aerys allows the specification of multiple virtual hosts in the same server instance. Each host
 * specifies its own application handler and mods.
 * 
 * The example below uses a static file server to host static files referenced in the HTML generated
 * on the dynamic server. To access the hosts by name make sure you add the names to your 
 * "/etc/hosts" file or alternatively the  "%SystemRoot%\system32\drivers\etc\hosts" in Windows.
 * 
 * An Aerys server may specify as many virtual host names as needed. For example, an application
 * could serve static files from one host using the built-in static file handler, websocket endpoints
 * from another host using the included websocket functionality and do both while serving a traditional
 * PHP application on yet another hostname.
 * 
 * To run this example:
 * 
 * $ php name_based_vhosts.php
 * 
 * Once the server has started, request http://aerys:1337/ in your browser or client of choice.
 */

use Aerys\Config\StaticFilesApp,
    Aerys\Config\ServerConfigurator;

require dirname(__DIR__) . '/autoload.php';

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

$myApp = function(array $asgiEnv) {
    if ($asgiEnv['REQUEST_URI'] == '/favicon.ico') {
        $response = [404, 'Not Found', $headers = [], '<h1>404 Not Found</h1>'];
    } else {
        $status = 200;
        $reason = 'OK';
        $body = '<html><body style="font-family: Sans-Serif;"><h1>Hello, world.</h1><hr/>';
        $body.= '<img src="http://static.aerys:1337/allofthethings.gif" width="480" height="335" alt="PHP! ALL OF THE THINGS!" /><hr/><br/>';
        $body.= 'Woah, dang! ^ That image came from a different domain!';
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

(new ServerConfigurator)->createServer($config)->listen();

