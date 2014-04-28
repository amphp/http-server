<?php

/**
 * @TODO
 *
 * To run this example:
 *
 * $ bin/aerys -c examples/005_routes.php
 *
 * Once started, load http://127.0.0.1:1337/ or http://localhost:1337/ in your browser.
 */

require_once __DIR__ . '/support/routing_includes.php';

$app = new (Aerys\HostConfig)
    ->setPort(1337)
    ->addRoute('GET', '/', 'BasicRouting::hello')
    ->addRoute('GET', '/info', 'BasicRouting::info')
    ->addRoute('GET', '/{arg1:\d+}/{arg2:\d+}/{arg3}', 'BasicRouting::args')
    ->addRoute('GET', '/static', 'BasicRouting::myStaticHandler')
    ->addRoute('GET', '/function', 'myRouteFunction')
    ->addRoute('GET', '/closure', $myRouteClosure)
;
