<?php

use Amp\ReactorFactory,
    Auryn\Provider,
    Aerys\Config\Bootstrapper,
    Aerys\Handlers\DocRoot\DocRootLauncher,
    Aerys\Handlers\Websocket\WebsocketHandler;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/multi_protocol_chat/LineFeedClient.php';
require __DIR__ . '/support/multi_protocol_chat/MyMultiProtocolChat.php';

// We have to pass the upgrade response callbacks to the ModUpgrade config definition so we
// instantiate them here.
$reactor = (new ReactorFactory)->select();
$websocketHandler = new WebsocketHandler($reactor);
$chatApplication = new MyMultiProtocolChat($reactor, $websocketHandler);

$config = [
    'my-chat-app' => [
        'listenOn'      => '*:80',
        'application'   => new DocRootLauncher([
            'docRoot'   => __DIR__ . '/support/multi_protocol_chat/docroot'
        ]),
        'mods' => [
            'upgrade' => [
               '/echo' => [ // <-- will capture all requests to /websocket-chat
                   'upgradeToken' => 'websocket',
                   'upgradeResponder' => [$chatApplication, 'answerWebsocketUpgradeRequest']
               ],
               '/line-feed-chat' => [ // <-- will capture all requests to /line-feed-chat
                   'upgradeToken' => 'line-feed-chat',
                   'upgradeResponder' => [$chatApplication, 'answerLineFeedUpgradeRequest']
               ]
           ]
        ]
    ]
];

// It's important to use the same event reactor for everything in the application. Since
// we already created one to instantiate our chat handler we need to tell Aerys to use
// this reactor when it fires up the server. By sharing it here, the Bootstrapper will
// automatically provide the shared reactor to anything in the configuration that needs it.
$injector = new Auryn\Provider;
$injector->alias('Amp\Reactor', get_class($reactor));
$injector->share($reactor);

(new Bootstrapper($injector))->createServer($config)->start();
