<?php

/**
 * Aerys makes writing synchronous applications trivial.
 *
 * @TODO Add more explanation
 *
 * To run this application:
 *
 *     $ bin/aerys -c examples/010_synchronous.php
 *
 * Once started, load http://127.0.0.1:1338/ or http://localhost:1338/ in your browser.
 */

require __DIR__ . '/../src/bootstrap.php';

// The routes referenced below are stored in this file
require __DIR__ . '/support/010_sync_includes.php';

$myApp = (new Aerys\App)
    ->setPort(1337)
    ->addRoute('GET', '/non-blocking', 'myNonBlockingRoute')
    ->addThreadRoute('GET', '/', 'myThreadRoute')
    ->addThreadRoute('GET', '/streaming', 'myStreamingThreadRoute')
    ->addThreadRoute('GET', '/custom', 'myCustomThreadResponseRoute')
    ->addResponder('myFallbackResponder')
;
