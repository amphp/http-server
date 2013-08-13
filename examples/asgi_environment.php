<?php

/**
 * This config file demonstrates a simple dynamic application. All requests arriving on port 1337
 * will be served a 200 response listing the ASGI environment variables associated with the request.
 * As you can see we've omitted the "name" attribute from our host definition in the $config array.
 * A name is not required if we're only hosting one site.
 * 
 * To run this server:
 * 
 * $ php aerys.php -c="/path/to/hello_world.php"
 * 
 * Once started you should be able to access the application at 127.0.0.1:1337 in your browser.
 */

$myApp = function(array $asgiEnv) {
    $body = '<html><body><h1>Hello, world.</h1>';
    $body.= '<h3>Your request environment is ...</h3>';
    $body.= '<pre>' . print_r($asgiEnv, TRUE) . '</pre>';
    $body.= '</body></html>';
    
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$config = [
    'my-site' => [
        'listenOn' => '*:1337',
        'application' => $myApp
    ]
];
