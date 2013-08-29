<?php

/**
 * Aerys insulates itself from fatal application errors by monitoring worker processes and
 * respawning servers as needed. This example demonstrates what happens when your application
 * triggers a fatal error. To generate this error, simply load this URI in your browser after
 * launching the server:
 * 
 *     http://127.0.0.1:1338/fatal
 * 
 * As you can see in the code below this will cause an E_ERROR. You'll see a 500 response in your
 * browser and the server will immediately respawn. All URIs except "/fatal" result in a simple
 * hello world response.
 * 
 * To run:
 * $ bin/aerys -c examples/ex105_fatal_error_recovery.php
 * 
 * Once started, load http://127.0.0.1:1338/ or http://localhost:1338/ in your browser.
 */

require __DIR__ . '/../vendor/autoload.php';

$myResponder = function($asgiEnv) {
    switch ($asgiEnv['REQUEST_URI_PATH']) {
        case '/fatal':
            $nonexistentObj->nonexistentMethod(); // <-- will cause a fatal E_ERROR
            break;
        default:
            $body = '<html><body><h1>Hello, world.</h1></body></html>';
            return [$status = 200, $reason = 'OK', $headers = [], $body];
    }
};

$myApp = (new Aerys\Framework\App)->setPort(1338)->addUserResponder($myResponder);
