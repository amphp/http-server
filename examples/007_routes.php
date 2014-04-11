<?php

/**
 * @TODO
 *
 * To run this example:
 *
 *     $ bin/aerys -c examples/007_routes.php
 *
 * Once started, load http://127.0.0.1:1338/ or http://localhost:1338/ in your browser.
 */

require_once  __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/support/007_routing_includes.php';

$app = new Aerys\Start\App;
$app->setPort(1338);

$app->addRoute('GET', '/', 'Ex007_BasicRouting::hello');
$app->addRoute('GET', '/info', 'Ex007_BasicRouting::info');
$app->addRoute('GET', '/{arg1:\d+}/{arg2:\d+}/{arg3}', 'Ex007_BasicRouting::args');
$app->addRoute('GET', '/static', 'Ex007_BasicRouting::myStaticHandler');
$app->addRoute('GET', '/function', 'ex007_my_function');
$app->addRoute('GET', '/closure', $ex007_closure);
