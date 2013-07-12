<?php

/**
 * examples/file_server.php
 * 
 * DEMO:
 * -----
 * 
 * @TODO Discuss static file serving
 * 
 * HOW TO RUN THIS DEMO:
 * ---------------------
 * 
 * $ php examples/file_server.php
 * 
 * Once the server has started, request http://127.0.0.1:80/ in your browser or client of choice.
 */

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
        ]
    ]
    
]])->start();

