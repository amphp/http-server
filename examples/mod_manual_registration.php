<?php

/**
 * examples/mod_manual_registration.php
 * 
 * DEMO:
 * -----
 * In lieu of using the Bootrapper configuration array to specify host mods we can manually register
 * mod instances with the server. Any object that implements one of the server mod interfaces
 * below can be registered:
 * 
 *  - OnHeadersMod
 *  - BeforeResponseMod
 *  - AfterResponseMod
 * 
 * Note that because mods are assigned on a per-host basis, if you register a mod that doesn't have
 * a matching host already assigned to the server you will trigger an exception. Any mod can be
 * registered using the `Aerys\Server::registerMod($hostId, $mod, $priorityMap)` method. In the
 * example code below we create our own custom mod class and register it to change all responses
 * entity bodies before they are sent to the client. The mod is applied to ALL registered hosts
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
 * 
 * HOW TO RUN THIS DEMO:
 * ---------------------
 * 
 * $ php examples/mod_manual_registration.php
 * 
 * Once the server has started, request http://127.0.0.1:1337/ in your browser or client of choice.
 * You'll see that on each HTTP request the mod changes the contents of the entity body assigned
 * by the application handler before the response is returned to the client.
 */

use Aerys\Config\Bootstrapper,
    Aerys\Mods\BeforeResponseMod,
    Aerys\Server;

require dirname(__DIR__) . '/autoload.php';

class MyCustomMod implements BeforeResponseMod {
    
    private $server;
    
    function __construct(Server $server) {
        $this->server = $server;
    }
    
    function beforeResponse($requestId) {
        $asgiResponse = $this->server->getResponse($requestId);
        if ($asgiResponse[0] == 200) {
            $newBody = '<html><body><h1>Zanzibar!</h1><p>(Assigned by MyCustomMod)</p></body></html>';
            $asgiResponse[3] = $newBody;
            $this->server->setResponse($requestId, $asgiResponse);
        }
    }
}

$myApp = function(array $asgiEnv) {
    $body = "You won't ever see this because our mod will alter it.";
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$server = (new Bootstrapper)->createServer([[
    'listenOn' => '*:1337',
    'application' => $myApp
]]);

$myCustomMod = new MyCustomMod($server);
$server->registerMod('*', $myCustomMod);
$server->start();
