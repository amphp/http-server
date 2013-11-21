<?php

/**
 * Every Aerys host responder is passed an `$asgiEnv` array when invoked. This array contains all
 * the information needed to respond appropriately to client requests. Developers used to PHP web
 * SAPIs should note that this array is roughly similar to the `$_SERVER` array. Run the server and
 * load it in your browser to see the contents of the ASGI environment for your requests.
 */

require __DIR__ . '/../vendor/autoload.php';

$reactor = (new Alert\ReactorFactory)->select();
$server = new Aerys\Server($reactor);

$address = '*';
$port = 80;
$name = 'localhost';
$app = function($asgiEnv) {
    $body = '<html><body><h1>ASGI Environment</h1><pre>' . print_r($asgiEnv, TRUE) . '</pre></body></html>';
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$host = new Aerys\Host($address, $port, $name, $app);

$server->start($host);
$reactor->run();
