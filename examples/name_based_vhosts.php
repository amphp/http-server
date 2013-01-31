<?php

/**
 * examples/name_based_vhosts.php
 * 
 * Aerys allows the specification of multiple virtual hosts in the same server instance. Each host
 * specifies its own set of options and modules.
 * 
 * The example below uses a static file server to host static files referenced in the HTML generated
 * on the dynamic server. To access the hosts by name make sure you add the names to your 
 * "/etc/hosts" file or alternatively the  "%SystemRoot%\system32\drivers\etc\hosts" in windows.
 * 
 * $ php name_based_vhosts.php
 * 
 * Once the server has started, request http://aerys/ in your browser or client of choice.
 */

require dirname(__DIR__) . '/autoload.php';

date_default_timezone_set('GMT');

$dynamicHandler = function(array $asgiEnv) {
    if ($asgiEnv['REQUEST_URI'] == '/favicon.ico') {
        $response = [404, [], '<h1>404 Not Found</h1>'];
    } else {
        $status = 200;
        $body = '<html><body><h1>Hello, world.</h1><hr/>';
        $body.= '<img src="http://static.aerys/funtheysaid.png" alt="Img from static host"/><hr/><br/>';
        $body.= 'Woah, dang! ^ That image came from a different domain!';
        $body.= '</body></html>';
        $response = [$status, [], $body];
    }
    
    return $response;
};

$config = [
    'host.dynamic' => [
        'listen'    => '*:80',
        'name'      => 'aerys',
        'handler'   => $dynamicHandler
    ],
    'host.static' => [
        'listen'    => '*:80',
        'name'      => 'static.aerys',
        'handler'   => new Aerys\Handlers\Filesys(__DIR__ . '/file_server_root')
    ]
];

(new Aerys\ServerFactory)->createServer($config)->listen();

