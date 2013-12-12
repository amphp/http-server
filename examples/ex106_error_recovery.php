<?php

/**
 * Aerys insulates itself from fatal application errors by monitoring worker processes and
 * respawning servers as needed. This example demonstrates what happens when your application
 * triggers a fatal error. To generate this error, simply load this URI in your browser after
 * launching the server:
 * 
 *     http://127.0.0.1/fatal
 * 
 * As you can see in the code below this will cause an E_ERROR. You'll see a 500 response in your
 * browser and the server will immediately respawn.
 * 
 * Servers also generate a 500 response if your application throws an uncaught exception while
 * responding to a request. This behavior is demonstrated here:
 * 
 *     http://127.0.0.1/exception
 * 
 * Note that error traceback is displayed by default. This behavior can be controlled using the
 * server's "showErrors" option. Production servers should set this value to FALSE to avoid
 * displaying error output to end-users.
 * 
 * To run:
 * $ bin/aerys -c examples/ex106_error_recovery.php
 * 
 * Once started, load http://127.0.0.1/ in your browser.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$myResponder = function($request) {
    switch ($request['REQUEST_URI_PATH']) {
        case '/fatal':
            $nonexistentObj->nonexistentMethod(); // <-- will cause a fatal E_ERROR
            break;
        case '/exception':
            throw new \Exception('Test Exception');
            break;
        default:
            $li = '
            <p>This app demonstrates fatal error and exception recovery.</p>
            <ul>
                <li><a href="/fatal">/fatal</a></li>
                <li><a href="/exception">/exception</a></li>
            </ul>';
            
            return "<html><body><h1>Error Recovery</h1>{$li}</body></html>";
    }
};

$myApp = (new Aerys\Framework\App)->addResponder($myResponder);
