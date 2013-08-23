<?php

/**
 * This demo server shows how to use static class methods, global functions and closures when
 * responding to requests.
 * 
 * To run:
 * $ bin/aerys -c examples/ex202_more_routing.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/support/Ex202_MoreRouting.php';

$app = new Aerys\Framework\App;

$app->addRoute('GET', '/', 'Ex202_MoreRouting::myStaticHandler');
$app->addRoute('GET', '/function', 'ex202_my_function');
$app->addRoute('GET', '/closure', $ex202_closure);
