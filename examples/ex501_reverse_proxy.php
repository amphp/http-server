<?php

use Aerys\Framework\App;
require __DIR__ . '/../vendor/autoload.php';

$myApp = (new App)
    ->addWebsocket('/echo', 'MyWebsocketEndpointClass')
    ->setDocumentRoot('/path/to/static/file/directory')
    ->reverseProxyTo('192.168.1.5:1500', ['proxyPassHeaders' => [
        'Host'            => '$host',
        'X-Forwarded-For' => '$remoteAddr',
        'X-Real-Ip'       => '$serverAddr'
    ]]);