<?php

/**
 * This example utilizes Aerys's name-based virtual hosting capability to serve two separate domains
 * from the same server. Note that for this example to work as expected you'll need to have the two
 * domains pointing at your local machine. In *nix this is done in the `/etc/hosts` file. In windows
 * you'll instead want to set the following line in `%systemroot%\system32\drivers\etc\hosts` ...
 * 
 *     127.0.0.1     localhost aerys subdomain.aerys
 * 
 * Aerys servers may specify as many virtual host names as needed. For example, an application
 * could serve static files from one host using the built-in static file responder, websocket app
 * endpoints from another host using the websocket responder and do both while serving a dynamically
 * routed PHP application on yet another hostname.
 * 
 * This example answers all requests to http://aerys with a basic 200 response. Meanwhile all
 * requests to http://subdomain.aerys are handled from the static document root specified.
 * 
 * To run:
 * $ bin/aerys -c examples/ex102_multiple_hosts.php
 * 
 * Once started, load http://aerys/ or http://subdomain.aerys/ in your browser.
 */

require __DIR__ . '/../vendor/autoload.php';

$aerysApp = new Aerys\Framework\App;
$aerysApp->setName('aerys');
$aerysApp->addUserResponder(function() {
    $body = '<html><body><h1>Hello from http://aerys</h1></body></html>';
    return [$status = 200, $reason = 'OK', $headers = [], $body];
});

$subdomainApp = new Aerys\Framework\App;
$subdomainApp->setName('subdomain.aerys');
$subdomainApp->setDocumentRoot(__DIR__ . '/support/docroot');
