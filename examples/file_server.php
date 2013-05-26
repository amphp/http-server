<?php

// To run, execute this script and request http://127.0.0.1:1337/ in your browser

use Aerys\Config\Configurator,
    Aerys\Config\DocRootLauncher,
    Aerys\Handlers\DocRoot\DocRootHandler;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/ExampleChatEndpoint.php';

(new Configurator)->createServer([[
    'listenOn'      => '*:80',
    'application'   => new DocRootLauncher([
        'docRoot'   => __DIR__ . '/support/file_server_root',
        
        // --- ALL OTHER KEYS ARE OPTIONAL; DEFAULTS SHOWN BELOW --- //
        
        'indexes'                   => ['index.html', 'index.htm'],
        'indexRedirection'          => TRUE,
        'eTagMode'                  => DocRootHandler::ETAG_ALL,
        'expiresHeaderPeriod'       => 300,
        'defaultMimeType'           => 'text/plain',
        'customMimeTypes'           => [],
        'defaultTextCharset'        => 'utf-8',
        'cacheTtl'                  => 5,
        'memoryCacheMaxSize'        => 67108864,
        'memoryCacheMaxFileSize'    => 1048576
    ]),
    
    'mods' => [
        'log' => [
            'php://stdout' => 'common'
        ],
        'websocket' => [
            '/chat' => new ExampleChatEndpoint
        ],
    ]
    
]])->start();

