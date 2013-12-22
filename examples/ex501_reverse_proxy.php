<?php

/**
 * @TODO Add explanation
 * 
 * To run:
 * $ bin/aerys -c examples/ex501_reverse_proxy.php
 */
 
use Aerys\Framework\App;
require __DIR__ . '/../vendor/autoload.php';

$myApp = (new App)
    ->reverseProxyTo('192.168.1.5:1500', ['proxyPassHeaders' => [
        'Host'            => '$host',
        'X-Forwarded-For' => '$remoteAddr',
        'X-Real-Ip'       => '$serverAddr'
    ]])
;