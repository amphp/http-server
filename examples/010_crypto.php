<?php

/**
 * @TODO
 *
 * RUNNABLE EXAMPLE
 *
 * To run this example server:
 *
 * $ bin/aerys -c examples/010_crypto.php
 *
 * Once started, load https://domain1.com/ or https://domain2.com/ in your browser.
 */


/* --- https://domain1.com/ --------------------------------------------------------------------- */

$domain1 = (new Aerys\HostTemplate)->setName('domain1.com');

(clone $domain1)
    ->setPort(443) // port 443 is standard for encrypted https:// sites
    ->setCrypto('/path/to/domain1.pem')
    ->addResponder(function($request) {
        return '<html><body><h1>Hello, world (domain1).</h1></body></html>';
    })
;

// Redirect all unencrypted requests for domain1.com to our encrypted host
(clone $domain1)
    ->setPort(80) // <-- unencrypted requests arrive on port 80
    ->redirectTo('https://domain1.com') // <-- note the "https" in the redirect URI
;

/* --- https://domain2.com/ --------------------------------------------------------------------- */

(new Aerys\Host)
    ->setPort(443)
    ->setName('domain2.com')
    ->setCrypto('/path/to/domain2.pem')
    ->addResponder(function($request) {
        return '<html><body><h1>Hello, world (domain2).</h1></body></html>';
    })
;
