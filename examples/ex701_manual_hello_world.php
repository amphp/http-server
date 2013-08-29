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
 * 
 * Responses MUST always be returned as a four-element indexed array of the form:
 * 
 * [0] HTTP response status code
 * [1] Reason phrase; if empty Aerys will auto-fill the value according to the status code at [0]
 * [2] Array of Headers (optionally empty -- Aerys normalizes missing headers for you)
 * [3] The response body: NULL, string, seekable resource or Iterator instance (generators!)
 * 
 * If your app returns an Iterator body Aerys automatically handles streaming it to the client.
 */

require __DIR__ . '/../vendor/autoload.php';

$reactor = (new Alert\ReactorFactory)->select();
$server = new Aerys\Server($reactor);

$address = '127.0.0.1';
$port = 80;
$name = 'localhost';
$app = function() {
    $body = '<html><body><h1>Hello, world.</h1></body></html>';
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$host = new Aerys\Host($address, $port, $name, $app);

$server->addHost($host);
$server->listen();
$reactor->run();
