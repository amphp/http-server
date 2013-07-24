<?php

/**
 * Example bootstrapper config for built-in mods
 */

$config = [
    'dependencies' => [
        'shares' => [
            'MultiProtocolChat' // <-- pass the same instance to both websocket AND my-chat-upgrade mods
        ]
    ],
    'my-chat-app' => [
        'listenOn'      => '*:1337',
        'application'   => new DocRootLauncher([
            'docRoot'   => __DIR__ . '/support/file_server_root'
        ]),
        
        'mods' => [
            'log' => [
                'php://stdout' => 'common',
                '/var/log/mysite.log' => 'combined'
            ],
            'websocket' => [
                '/echo' => [
                    'endpointClass'   => 'SomeChat', // *required
                    'allowedOrigins'  => [],
                    'maxFrameSize'    => 2097152,
                    'maxMsgSize'      => 10485760,
                    'heartbeatPeriod' => 10,
                    'subprotocol'     => NULL
                ]
            ],
            'send-file' => [
                'docRoot'                => '/path/to/file_server_root', // *required
                'indexes'                => ['index.html', 'index.htm'],
                'indexRedirection'       => TRUE,
                'eTagMode'               => DocRootHandler::ETAG_ALL,
                'expiresHeaderPeriod'    => 300,
                'defaultMimeType'        => 'text/plain',
                'customMimeTypes'        => [],
                'defaultTextCharset'     => 'utf-8',
                'cacheTtl'               => 5,
                'memoryCacheMaxSize'     => 67108864,
                'memoryCacheMaxFileSize' => 1048576
            ],
            'error-pages' => [
                '404' => '/path/to/404.html',
                '500' => '/path/to/500.html'
            ],
            'expect' => [
                '/some/uri'       => function(array $asgiEnv){},
                '/some/other/uri' => function(array $asgiEnv){}
            ],
            'limit' => [
                'ipProxyHeader' => NULL,
                'limits' => [
                    60 => 100,
                    3600 => 2500
                ]
            ],
            'protocol' => [
                'options' => [
                    'socketReadTimeout' => -1,
                    'socketReadGranularity' => 65535
                ],
                'handlers' => [
                    'CustomProtocolHandlerClass'
                ]
            ]
        ]
    ]
];
