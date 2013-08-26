<?php

/**
 * @TODO Add explanation
 * 
 * To run:
 * $ bin/aerys -c examples/ex203_route_class_shortcuts.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/support/Ex203_RouteClassShortcuts.php';

$app = (new Aerys\Framework\App)
    ->addRouteClass('/', 'Ex203_RouteClassShortcuts')
    ->addRouteClass('/map', 'Ex203_RouteClassShortcutsWithMap', $methods = [
        'GET' => 'zanzibar'
    ])
;
