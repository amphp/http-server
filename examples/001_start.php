<?php

/**
 * Every Aerys "App" is a 1:1 mapping of a host name, IP address and listening port to a callable
 * responder. When a request arrives Aerys determines which App responder should be invoked and
 * routes the request to the appropriate application. You aren't *required* to actually set any of
 * the port/address/name values unless you need to modify the defaults values:
 *
 * App::setPort(int $port)          The app's TCP port number
 * App::setAddress(string $ip)      The app's IPv4 or IPv6 address number
 * App::setName(string $name)       The app's host (domain) name
 *
 *
 * ### Ports
 *
 * Any valid port number [1-65535] may be used. Port numbers lower than 256 are reserved for
 * well-known services (like HTTP on port 80) and port numbers less than 1024 require root
 * access on UNIX-like systems. If no value is specified port 80 is assumed.
 *
 *
 * ### Addresses
 *
 * The default wildcard IP value "*" translates to "all IPv4 interfaces" and is appropriate for
 * most scenarios. Valid values also include any IPv4 or IPv6 address. The string "[::]" denotes
 * an IPv6 wildcard.
 *
 *
 * ### Host Names
 *
 * The name is your application's domain (e.g. localhost or mysite.com or subdomain.mysite.com). A
 * name is not required if you only serve one application. If multiple applications (domain names)
 * are specified for your server each App MUST specify a name to differentiate it from the other
 * virtual hosts on the server.
 *
 *
 * To run this application:
 *
 *     $ bin/aerys -c examples/001_start.php
 *
 * Once started, load http://127.0.0.1:1338/ or http://localhost:1338/ in your browser.
 */

require __DIR__ . '/../src/bootstrap.php';

$myApp = new Aerys\Start\App;
$myApp->setPort(1338);
$myApp->setAddress('*');
$myApp->setName('localhost');
$myApp->addResponder(function($request) {
    return '<html><body><h1>Hello, world.</h1></body></html>';
});
