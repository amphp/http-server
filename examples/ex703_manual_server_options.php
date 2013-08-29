<?php

/**
 * An Aerys server has quite a few option settings available. Rather than list them individually
 * here, this example asks the server for an array of all current options and displays them in
 * our responses. We can set these options individually by calling `Aerys\Server::setOption()` or
 * use `Aerys\Server::setAllOptions()` to assign multiple values at once.
 */

require __DIR__ . '/../vendor/autoload.php';

$reactor = (new Alert\ReactorFactory)->select();
$server = new Aerys\Server($reactor);

$address = '127.0.0.1';
$port = 80;
$name = 'localhost';
$app = function($asgiEnv) use ($server) {
    $options = $server->getAllOptions();
    
    $body = '<html><body><h1>Server Options</h1>';
    $body.= '<pre>' . print_r($options, TRUE) . '</pre>';
    $body.= '</body></html>';
    
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$host = new Aerys\Host($address, $port, $name, $app);

$server->addHost($host);
$server->listen();
$reactor->run();
