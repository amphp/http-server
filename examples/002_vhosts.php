<?php

/**
 * Each Aerys App represents a separate host name. If you wish to run both mysite.com and
 * subdomain.mysite.com on the same server you must create two separate App instances. This example
 * serves two separate domains (http://aerys and http://subdomain.aerys).
 *
 * For this example to function you must modify your system's "hosts" file to point the two names
 * at your local machine. In *nix this is done in the /etc/hosts file. In windows you'll instead
 * want to set the same line in your %systemroot%\system32\drivers\etc\hosts file:
 *
 *     127.0.0.1     localhost aerys subdomain.aerys
 *
 * An Aerys server may specify as many virtual host names as needed. The code below serves simple
 * dynamic responses from the primary host (http://aerys) and static files for all requests to the
 * secondary (http://subdomain.aerys) host.
 *
 * To run this example:
 *
 *     $ bin/aerys -c examples/002_vhosts.php
 *
 * Once started, load http://aerys/ or http://subdomain.aerys/ in your browser.
 */

require __DIR__ . '/../src/bootstrap.php';

// --- Retern "hello world" on http://aerys (port 80, all IPv4 interfaces) -------------------------

$main = new Aerys\App;
$main->setName('aerys');
$main->addResponder(function() {
    return '<html><body><h1>Hello, World (http://aerys)!</h1></body></html>';
});

// --- Serve other things from http://subdomain.aerys (port 80, all IPv4 interfaces) ---------------

$subdomain = new Aerys\App;
$subdomain->setName('subdomain.aerys');
$subdomain->setDocumentRoot(__DIR__ . '/support/docroot/');
