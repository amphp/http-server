<?php

/* --- Host template for baseline settings ------------------------------------------------------ */

// Here we create a template with common settings that we can clone for other hosts.
$template = (new Aerys\HostTemplate)
    ->setAddress('*')
    ->setCrypto('/path/to/my-san-cert.pem')
;

/* --- https://mydomain.com/ -------------------------------------------------------------------- */

// Inherit the previously defined template settings
(clone $template)
    ->setName('mydomain.com')
    ->addResponder(function($request) {
        return '<html><body><h1>Hello, world (domain1).</h1></body></html>';
    })
;

/* --- https://static.mydomain.com/ ------------------------------------------------------------- */

// Inherit the previously defined template settings
(clone $template)
    ->setName('static.mydomain.com')
    ->setRoot('/path/to/static/files')
;
