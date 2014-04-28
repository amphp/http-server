<?php

/**
 * @TODO Add explanation
 *
 * To run this application:
 *
 * $ bin/aerys -c examples/010_synchronous.php
 *
 * Once started, load http://127.0.0.1:1337/ or http://localhost:1337/ in your browser.
 */

require __DIR__ . '/support/sync_includes.php';

$myApp = (new Aerys\HostConfig)
    ->setPort(1337)
    ->addRoute('GET', '/non-blocking', 'myNonBlockingRoute')
    ->addThreadRoute('GET', '/', 'myThreadRoute')
    ->addThreadRoute('GET', '/streaming', 'myStreamingThreadRoute')
    ->addThreadRoute('GET', '/custom', 'myCustomThreadResponseRoute')
    ->addResponder('myFallbackResponder')
;
