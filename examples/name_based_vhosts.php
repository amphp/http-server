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
 * Once the server has started, request http://aerys:1337/ in your browser or client of choice.
 */

require dirname(__DIR__) . '/autoload.php';

date_default_timezone_set('GMT');

$dynamicHandler = function(array $asgiEnv) {
    if ($asgiEnv['REQUEST_URI'] == '/favicon.ico') {
        $response = [404, 'Not Found', [], '<h1>404 Not Found</h1>'];
    } else {
        $status = 200;
        $reason = 'OK';
        $body = '<html><body><h1>Hello, world.</h1><hr/>';
        $body.= '<img src="http://static.aerys:1337/allofthethings.png" width="480" height="335" alt="PHP! ALL OF THE THINGS!" /><hr/><br/>';
        $body.= 'Woah, dang! ^ That image came from a different domain!';
        $body.= '</body></html>';
        $response = [$status, $reason, [], $body];
    }
    
    return $response;
};

$config = [
    'host.dynamic' => [
        'listen'    => '127.0.0.1:1337',
        'name'      => 'aerys',
        'handler'   => $dynamicHandler
    ],
    'host.static' => [
        'listen'    => '127.0.0.1:1337',
        'name'      => 'static.aerys',
        'handler'   => new Aerys\Http\Filesys(__DIR__ . '/file_server_root')
    ]
];

(new Aerys\Http\HttpServerFactory)->createServer($config)->listen();

