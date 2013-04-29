<?php

// To run, execute this script and request http://127.0.0.1:1337/ in your browser

use Aerys\Config\Configurator,
    Aerys\Config\StaticFilesApp,
    Aerys\Handlers\StaticFiles\Handler;

require dirname(__DIR__) . '/autoload.php';

(new Configurator)->createServer([[
    'listenOn'      => '127.0.0.1:1337',
    'application'   => new StaticFilesApp([
        'docRoot'   => __DIR__ . '/support_files/file_server_root',
        
        // --- ALL OTHER KEYS ARE OPTIONAL; DEFAULTS SHOWN BELOW --- //
        
        'indexes'                   => ['index.html', 'index.htm'],
        'indexRedirection'          => TRUE,
        'eTagMode'                  => Handler::ETAG_ALL,
        'expiresHeaderPeriod'       => 300,
        'defaultMimeType'           => 'text/plain',
        'customMimeTypes'           => [],
        'defaultTextCharset'        => 'utf-8',
        'cacheTtl'                  => 5,
        'memoryCacheMaxSize'        => 67108864,
        'memoryCacheMaxFileSize'    => 1048576
    ])
]])->start();

