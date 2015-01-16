<?php

/**
 * @TODO
 *
 *
 * RUNNABLE EXAMPLE
 *
 * To run this example server:
 *
 * $ bin/aerys -c examples/010_crypto.php
 *
 * Once started, load https://domain1.com:1337/ or https://domain2.com:1337/ in your browser.
 */


namespace Aerys;

/* --- https://domain1.com:1337/ ---------------------------------------------------------------- */

$domain1 = (new Host)
    ->setPort(1337)
    ->setName('domain1.com')
    ->setCrypto('/home/daniel/dev/ca/server.pem')
    ->addResponder(function($request) {
        return '<html><body><h1>Hello, world (domain1).</h1></body></html>';
    })
;

/* --- https://domain2.com:1337/ ---------------------------------------------------------------- */

$domain2 = (new Host)
    ->setPort(1337)
    ->setName('domain2.com')
    ->setCrypto('/home/daniel/dev/ca/server2.pem')
    ->addResponder(function($request) {
        return '<html><body><h1>Hello, world (domain2).</h1></body></html>';
    })
;
