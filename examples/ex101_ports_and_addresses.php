<?php

/**
 * Every Aerys "App" is a 1:1 mapping of a host name, IP address and listening port to a callable
 * responder. When a request arrives Aerys determines which App responder should be invoked and
 * routes the request to the appropriate handler.
 * 
 * You aren't *required* to actually set any of the port/address/name values unless you need to
 * modify the defaults.
 * 
 * Any valid port number [1-65535] may be used. Port numbers lower than 256 are reserved for
 * well-known services (like HTTP on port 80) and port numbers less than 1024 require root
 * access on UNIX systems. If no value is specified port 80 is assumed.
 * 
 * The default wildcard IP value "*" translates to "all IPv4 interfaces" and is appropriate for
 * most scenarios. Valid values also include any IPv4 or IPv6 address. The string "[::]" denotes
 * an IPv6 wildcard.
 * 
 * The name is your application's domain (e.g. localhost or mysite.com or subdomain.mysite.com). A
 * name is not required if you only serve one application. If multiple applications (domain names)
 * are specified for your server each App MUST specify a name to differentiate it from the other
 * virtual hosts on the server.
 *
 * Finally, this example demonstrates adding a user responder for all requests. Aerys provides far
 * more robust ways to write applications then the closure responder demonstrated below, but for now
 * it serves to send a "hello world" response to all requests arriving on 127.0.0.1:1338
 * 
 * To run:
 * $ bin/aerys -c examples/ex101_ports_and_addresses.php
 * 
 * Once started, load http://127.0.0.1:1338/ or http://localhost:1338/ in your browser.
 */

require __DIR__ . '/../vendor/autoload.php';

$myResponder = function($asgiEnv) {
    $body = '<html><body><h1>Hello, world.</h1></body></html>';
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$myApp = (new Aerys\Framework\App)
    ->setPort(1338)
    ->setAddress('127.0.0.1')
    ->setName('localhost')
    ->addUserResponder($myResponder)
;
