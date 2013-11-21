<?php

/**
 * Aerys has many server-wide options available for customization. To assign these options, the
 * server binary looks for an optional `Aerys\Framework\ServerOptions` instance in your config file.
 * The server operates with sensible defaults, but if you want to customize these values the
 * `ServerOptions` object is the place to do it. Note again that server-wide options apply to ALL
 * apps registered on your server.
 * 
 * The example below sets only a small number of the available server options. To see a full and
 * up-to-date list of possible options please consult the `Aerys\Server` source code.
 * 
 * To run:
 * $ bin/aerys -c examples/ex104_server_options.php
 * 
 * Once started, load http://127.0.0.1/ in your browser.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$serverOptions = (new Aerys\Framework\ServerOptions)->setAllOptions([
    'maxConnections'    => 2500,
    'maxRequests'       => 100,
    'allowedMethods'    => ['GET', 'POST', 'PUT']
]);

$userResponder = function() {
    $body = '<html><body><h1>Hello, world.</h1></body></html>';
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$myApp = (new Aerys\Framework\App)->addUserResponder($userResponder);
