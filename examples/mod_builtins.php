<?php

/**
 * examples/mod_builtins.php
 * 
 * INTRO TO AERYS MODS:
 * --------------------
 * 
 * Aerys provides multiple options for configuring server mods:
 * 
 *  - Define the mod configuration array for built-in mods in the Bootstrapper config
 *  - Directly call Server::registerMod($hostId, $modInstance, $priorityMap)
 *  - Registering custom mod keys and launcher classes with the Bootstrapper
 * 
 * When using the Bootstrapper configuration array mods are referenced by their (case-sensitive)
 * key inside the "mods" array of a host block. Unrecognized keys will trigger a ConfigException at
 * bootstrap time. The built-in mod keys are:
 * 
 *  - log
 *  - websocket
 *  - send-file
 *  - error-pages
 *  - expect
 *  - limit
 * 
 * Each mod definition accepts a single array value that is passed to the registered launcher class.
 * 
 * DEMO:
 * -----
 * 
 * The example code below demonstrates how to apply the built-in log mod for a given host. Because
 * the built-in mods are always available by default using the Bootstrapper configuration array we
 * can simply include the relevant configuration values to use the log mod. Note that though each
 * mod is configured using an array of values the format of that array varies and is specific to
 * the individual mod in question.
 * 
 * HOW TO RUN THIS DEMO:
 * ---------------------
 * 
 * $ php examples/mod_builtins.php
 * 
 * Once the server has started, request http://127.0.0.1:1337/ in your browser or client of choice.
 * You'll see that on each HTTP request the log mod outputs its data to the console as configured
 * in the below code.
 */

use Aerys\Config\Bootstrapper;

require dirname(__DIR__) . '/autoload.php';

$myApp = function(array $asgiEnv) {
    $body = '<html><body><h1>Hello, World.</h1></body></html>';
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

(new Bootstrapper)->createServer([[
    'listenOn'      => '*:1337',
    'application'   => $myApp,
    'mods' => [
        'log'   =>  [
            'php://stdout' => 'common',
        ]
    ]
]])->start();

