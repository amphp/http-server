<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/support/Ex401_WebsocketEchoEndpoint.php';

$myWebsocketApp = (new Aerys\Framework\App)
    ->setReverseProxy('192.168.1.5:1500')
    ->setDocumentRoot(__DIR__ . '/support/docroot/websockets')
    ->addWebsocket('/echo', 'Ex401_WebsocketEchoEndpoint');