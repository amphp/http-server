<?php

use Aerys\Handlers\DocRoot\DocRootLauncher,
    Aerys\Handlers\DocRoot\DocRootHandler;

/**
 * Though it's possible to directly start a static file server from the command line, app-specific
 * configuration directives necessitate the use of a config file. This example demonstrates how
 * to start a file server. The example also demonstrates how Aerys uses "Launcher" classes to hide
 * the implementation details needed to instantiate and prepare built-in application handlers for
 * use with the server. This layer of abstraction decouples the configuration format from the
 * instantiation of the relevant objects and allows us to use simple associative arrays to assign
 * options in the built-in handlers.
 * 
 * To run this server:
 * 
 * $ php aerys.php -c="/path/to/file_server.php"
 * 
 * Once started you should be able to access the application at the IP address http://127.0.0.1/ or
 * http://localhost/ in your browser. Note that in *nix systems root permissions are generally 
 * required to bind to low port numbers (like 80).
 */

$config = [
    'my-site' => [
        'listenOn'    => '*:80',
        'name'        => 'localhost',
        'application' => new DocRootLauncher([
            'docRoot' => __DIR__ . '/support/static_files',
            
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
        ])
    ]
];
