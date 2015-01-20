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
 * Once started, load https://domain1.com/ or https://domain2.com/ in your browser.
 */


use Aerys;

/* --- https://domain1.com/ --------------------------------------------------------------------- */

$domain1 = (new Host)
    ->setName('domain1.com')
    ->setCrypto('/path/to/domain1.pem')
    ->addResponder(function($request) {
        return '<html><body><h1>Hello, world (domain1).</h1></body></html>';
    })
;

/* --- https://domain2.com/ --------------------------------------------------------------------- */

$domain2 = (new Host)
    ->setName('domain2.com')
    ->setCrypto('/path/to/domain2.pem')
    ->addResponder(function($request) {
        return '<html><body><h1>Hello, world (domain2).</h1></body></html>';
    })
;
