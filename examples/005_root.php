<?php

/**
 * @TODO Add explanation
 *
 * To run:
 *
 * $ bin/aerys -c examples/005_root.php
 *
 * Once started, load http://127.0.0.1:1337/ or http://localhost:1337/ in your browser.
 */

$myFileServer = (new Aerys\Host)
    ->setPort(1337)
    ->setRoot(__DIR__ . '/support/docroot')
    ->addRoute('GET', '/dynamic', function() {
        return '<html><body><h1>Dynamic Resource</h1></body></html>';
    })
;
