<?php

/**
 * This config file demonstrates the most basic dynamic application. All requests arriving on port
 * 1337 are served a 200 response with an basic HTML body. We don't have to specify any additional
 * headers because Aerys handles this for us as needed. Additionally, the reason phrase is optional
 * and will be automatically assigned according to the status code if not specified.
 * 
 * To run this server:
 * 
 * $ php aerys.php -c="/path/to/hello_world.php"
 * 
 * Once started you should be able to access the application at 127.0.0.1:1337 in your browser.
 */

$myApp = function(array $asgiEnv) {
    $body = '<html><body><h1>Hello, world.</h1></body></html>';
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$config = [
    'my-site' => [
        'listenOn' => '*:1337',
        'application' => $myApp
    ]
];
