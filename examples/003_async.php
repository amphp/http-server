<?php

/**
 * @TODO Add explanation
 *
 * To run:
 *
 * $ bin/aerys -c examples/003_async.php
 *
 * Once started, load http://127.0.0.1:1337/ or http://localhost:1337/ in your browser.
 */

require __DIR__ . '/support/async_includes.php';

$myApp = (new Aerys\HostConfig)
    ->setPort(1337)
    ->addRoute('GET', '/', 'asyncResponder')
    ->addRoute('GET', '/multi', 'multiAsyncResponder')
    ->addRoute('GET', '/group1', 'group1AsyncResponder')
    ->addRoute('GET', '/group2', 'group2AsyncResponder')
;
