<?php

/**
 * This example demonstrates the interplay of dynamic route endpoints and static files. Any request
 * that doesn't match a dynamic route is handled as a static file request. The default responder
 * invocation order executes dynamic route responders before the static docroot responder. This
 * order can be easily modified using the `App::orderResponders` method like e.g.:
 * 
 *     $app->orderResponders([
 *         'docroot',
 *         'routes'
 *     ]);
 * 
 * The above response order causes the static docroot responder to be invoked first. The routes
 * responders would then only be invoked if the docroot responder returned a 404 response.
 * 
 * To run:
 * 
 * $ bin/aerys -c examples/ex302_static_files_with_dynamic_routes.php
 * 
 * Once started, load http://127.0.0.1/ in your browser.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/support/Ex302_StaticAndDynamic.php';

$app = new Aerys\Framework\App;

$app->addRoute('GET', '/', 'ex302_static_and_dynamic');
$app->setDocumentRoot(__DIR__ . '/support/docroot');
