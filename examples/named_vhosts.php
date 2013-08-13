<?php

/**
 * This example utilizes Aerys's name-based virtual hosting capability to serve two separate domains
 * from the same server. Note that for this example to work as expected you'll need to have the two
 * domains pointing at your local machine. In *nix this is done in the `/etc/hosts` file. In windows
 * you'll instead want to add the following line to `%systemroot%\system32\drivers\etc\hosts` ...
 * 
 *     127.0.0.1     localhost aerys1 aerys2
 * 
 * Aerys servers may specify as many virtual host names as needed. For example, an application
 * could serve static files from one host using the built-in static file handler, websocket app
 * endpoints from another host using the websocket handler and do both while serving a traditional
 * dynamic PHP application on yet another hostname.
 * 
 * To run this server:
 * 
 * $ php aerys.php -c="/path/to/named_vhosts.php"
 * 
 * Once the server has started, request http://aerys1/ and http://aerys2 in your browser.
 */

$aerysApp1 = function(array $asgiEnv) {
    $body = '<html><body><h1>Aerys App 1</h1></body></html>';
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$aerysApp2 = function(array $asgiEnv) {
    $body = '<html><body><h1>Aerys App 2</h1></body></html>';
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$config = [
    'host.dynamic' => [
        'listenOn'      => '*:80',
        'name'          => 'aerys1', // <--- ADD NAME TO YOUR HOSTS FILE OR THE EXAMPLE WON'T WORK
        'application'   => $aerysApp1
    ],
    'host.static' => [
        'listenOn'      => '*:80',
        'name'          => 'aerys2', // <--- ADD NAME TO YOUR HOSTS FILE OR THE EXAMPLE WON'T WORK
        'application'   => $aerysApp2
    ]
];
