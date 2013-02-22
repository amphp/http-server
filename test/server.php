<?php

use Aerys\Http\HttpServerFactory;

date_default_timezone_set('GMT');

require dirname(__DIR__) . '/autoload.php';

$handler = function(array $asgiEnv, $requestId) {
    return [200, 'OK', [], '<html><body><h1>Hello, world.</h1>1234567890123456789012345678901</body></html>'];
    /*
    if (!$asgiEnv['ASGI_LAST_CHANCE']) {
        return [100, 'Continue Bitch', [], NULL];
        return NULL;
    } elseif ($asgiEnv['REQUEST_URI'] == '/sendfile') {
        return [200, 'OK', ['X-Sendfile' => __DIR__ .'/www/test.txt'], NULL];
    } elseif ($asgiEnv['REQUEST_URI'] == '/favicon.ico') {
        return [404, 'Not Found', [], '<h1>404 Not Found</h1>'];
    } elseif ($asgiEnv['ASGI_INPUT']) {
        $input = $asgiEnv['ASGI_INPUT'];
        $entity = stream_get_contents($input);
        
        return [200, 'OK', [], "Entity Received: $entity"];
    } else {
        return [200, 'OK', [], '<html><body><h1>Hello, world.</h1></body></html>'];
    }
    */
};

$config = [
    
    'globals' => [
        'opts' => [
            'maxConnections'            => 0,
            'maxRequestsPerSession'     => 0,
            'idleConnectionTimeout'     => 30,
            'maxStartLineSize'          => 2048,
            'maxHeadersSize'            => 8192,
            'maxEntityBodySize'         => 2097152,
            'tempEntityDir'             => NULL,
            'defaultContentType'        => 'text/html',
            'defaultCharset'            => 'utf-8',
            'disableKeepAlive'          => FALSE,
            'sendServerToken'           => FALSE,
            'autoReasonPhrase'          => TRUE,
            'errorLogFile'              => NULL,
            'handleBeforeBody'          => FALSE,
            'normalizeMethodCase'       => TRUE,
            //'defaultHosts'              => ['127.0.0.1:1337' => 'aerys:1337'],
            'dontCombineHeaders'        => ['Set-Cookie'],
            'allowedMethods'            => [
                'GET',
                'HEAD',
                'OPTIONS',
                'POST',
                'PUT',
                'TRACE',
                'DELETE'
            ],
        ],
        /*
        'tls'   => [
            '127.0.0.1:1500' => [
                'localCertFile'         => dirname(__DIR__) . '/mycert.pem',
                'certPassphrase'        => '42 is not a legitimate passphrase'
            ]
        ],
        
        'mods'  => [
            // Any mod you want applied to all hosts should be specified here.
            // If a mod exists with the same key in a host config block it will
            // override the global instance specified here.
        ]
        */
    ],
    
    // --- ALL OTHER KEYS ARE CONSIDERED HOST CONTAINERS ---
    /*
    'myHost.secure' => [
        'listen'    => '127.0.0.1:1500', // <-- we specified a TLS definition in the "globals" section
        'name'      => 'aerys', // <--- optional
        'handler'   => $handler
    ],
    */
    
    /*
    'myHost.static' => [
        'listen'    => '127.0.0.1:1337', // <-- we specified a TLS definition in the "globals" section
        'name'      => 'static.aerys',
        'handler'   => new Aerys\Http\Filesys(__DIR__ . '/www')
    ],
    */
    
    'myHost.insecure' => [
        'listen'    => '127.0.0.1:1337',
        //'name'      => 'aerys', // <-- optional, defaults to 127.0.0.1
        'handler'   => $handler,
        'mods'      => [
            /*
            'mod.log'   =>  [
                'logs' => [
                    'php://stdout' => 'common'
                ]
            ],
            
            'mod.limit' => [
                'ipProxyHeader' => NULL, // use this header's value as the ip if available (helpful behind proxies)
                'onLimitCmd' => NULL,
                'onLimitCallback' => NULL,
                'limits' => [
                    60 => 10, // send a 429 if client has made > 200 requests in the past 60 seconds
                ]
            ],
            
            'mod.errorpages' => [
                404 => [__DIR__ .'/errorpages/404.html', 'text/html; charset=utf-8'],
            ],
            
            // All sendfile keys are optional; these are the defaults:
            'mod.sendfile' => [
                'docRoot' => '/',
                'staleAfter' => 60,
                'types' => [],
                'eTagMode' => Aerys\Http\Filesys::ETAG_ALL
            ],
            
            'mod.expect' => [
                '/' => function() { return FALSE; }
            ]
            */
            
            // --- INCOMPLETE MODS ---
            
            // @todo mod.redirect
            // @todo mod.rewrite
            // @todo mod.block
        ]
    ]
];

$server = (new HttpServerFactory)->createServer($config)->listen();




