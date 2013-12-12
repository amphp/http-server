<?php

/**
 * This example demonstrates how to manually interact with the `Aerys\Server` and `Aerys\Host`
 * classes to run an HTTP server on port 80.
 *
 * For any Aerys server to function we need to tell it how to respond to requests. We do this by
 * registering "hosts." Each `Aerys\Host` has a 1:1 corellation to a domain name hosted by your
 * server. In this example we create a callable to respond to requests. We then create an
 * `Aerys\Host` using our app callable and the relevant IP/PORT/NAME on which the host will listen.
 * When requests arrive our app will respond with a simple 200 reply and an HTML hello world
 * message. Note that we don't have to specify any headers in our response array because Aerys takes
 * care of missing headers for us.
 */

require __DIR__ . '/../vendor/autoload.php';

$reactor = (new Alert\ReactorFactory)->select();
$server = new Aerys\Server($reactor);

$address = '*';
$port = 80;
$name = 'localhost';
$host = new Aerys\Host($address, $port, $name, function($request) {
    return '<html><body><h1>Hello, world.</h1></body></html>';
});

$server->start($host);
$reactor->run();
