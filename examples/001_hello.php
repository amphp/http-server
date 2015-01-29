<?php

/**
 * GLOBAL SERVER OPTIONS
 *
 * Global server options are defined using a constant array in the global namespace. Use
 * the AERYS_OPTIONS constant to define options applicable to all hosts on the server:
 *
 *     <?php
 *     const AERYS_OPTIONS = [
 *         "MAX_CONNECTIONS" => 1000,
 *         "MAX_REQUESTS" => 100,
 *         "KEEP_ALIVE_TIMEOUT" => 5,
 *     ];
 *
 *     // Individual host definitions here
 *
 *
 * HOSTS
 *
 * Aerys exposes the Host class to configure the application you wish to run for each
 * individual domain (host) in your server. Defining multiple Host objects allows a server to
 * expose multiple HTTP virtual hosts. For example:
 *
 *     <?php
 *     namespace Aerys;
 *
 *     // Global server option constants here
 *
 *     // --- mysite.com (listens on port 80, all IPv4 interfaces) ---------------------------------
 *     $mysite = (new Host('mysite.com'))->addResponder(function($request) {
 *         return '<html><body><h1>Hello, world.</h1></body></html>';
 *     });
 *
 *     // --- static.mysite.com (listens on port 80, all IPv4 interfaces) --------------------------
 *     $subdomain = (new Host('static.mysite.com')->setRoot('/path/to/static/files');
 *
 *
 * The above example binds two hosts: mysite.com and static.mysite.com. The first returns a generic
 * "hello world" response for all requests it receives and the second acts as a static file server.
 * If no host name is passed to Host::__construct() then "localhost" is assumed. Servers
 * exposing only one host are not required to set the domain name in the Host constructor. A
 * name is required when serving more than one host in a server.
 *
 *
 * PORT NUMBERS
 *
 * Any valid port number [1-65535] may be specified using Host::setPort(). If no value is
 * specified port 80 is assumed.
 *
 *
 * IP ADDRESSES
 *
 * The default wildcard IP value "*" translates to "all IPv4 interfaces" and is appropriate for
 * most scenarios. Valid values also include any IPv4 or IPv6 address. The string "[::]" denotes
 * an IPv6 wildcard. A host's IP address is assigned with Host::setAddress(). If no address
 * is assigned the server assumes the IPv4 wildcard ("*").
 *
 *
 * RUNNABLE EXAMPLE
 *
 * To run this example server:
 *
 * $ bin/aerys -c examples/001_hello.php
 *
 * Once started, load http://127.0.0.1:1337/ or http://localhost:1337/ in your browser.
 */


/* --- Global server options here --------------------------------------------------------------- */

const AERYS_OPTIONS = [
    "KEEP_ALIVE_TIMEOUT" => 30
];

/* --- http://localhost:1337/ or http://127.0.0.1:1337/  (all IPv4 interfaces) ------------------ */

$myHost = (new Aerys\Host)
    ->setPort(1337)
    ->setAddress('*')
    ->addResponder(function($request) {
        return '<html><body><h1>Hello, world.</h1></body></html>';
    })
;