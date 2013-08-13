<?php

/**
 * Applications can manually register custom server mods by specifying callbacks in the
 * "aerys.beforeStart" configuration array. Each callback in this array will be invoked and passed
 * the generated `Aerys\Server` instance prior to server's socket binding and start routine.
 * 
 * In the example code below we create our own custom mod class and register it to change all
 * response bodies before they are sent to the client. The mod is applied to ALL registered hosts
 * because we use the asterisk (*) host ID:
 * 
 *     $server->registerMod('*', $myCustomMod);
 * 
 * Alternatively we could also do any of the following as long as it matches at least one host
 * registered with the server:
 * 
 *     $server->registerMod('*:1337', $myCustomMod); // match all hosts listening on port 1337
 *     $server->registerMod('mysite.com:80', $myCustomMod); // only match mysite.com on port 80
 *     $server->registerMod('mysite.com:*', $myCustomMod); // match mysite.com on any port
 */

require __DIR__ . '/support/MyBeforeResponseMod.php'; // <-- Our custom mod class file

$myApp = function(array $asgiEnv) {
    $body = "You won't ever see this because our mod will alter it.";
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$myModEnabler = function(Aerys\Server $server) {
    $myCustomMod = new MyBeforeResponseMod($server);
    $server->registerMod('*', $myCustomMod); // <-- register the mod for all hosts
};

$config = [
    'my-app' => [
        'listenOn' => '*:1337',
        'application' => $myApp
    ],
    'aerys.beforeStart' => [
        $myModEnabler
    ]
];
