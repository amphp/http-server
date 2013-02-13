<?php

use Aerys\Server, 
    Aerys\ServerFactory;

date_default_timezone_set('GMT');
error_reporting(E_ALL);
set_error_handler(function($errNo, $errStr, $errFile, $errLine) {
    $msg = "$errStr in $errFile on line $errLine";
    if (error_reporting() != 0) {
        throw new ErrorException($msg, $errNo);
    }
    
    //echo $msg, "\n";
});

require dirname(__DIR__) . '/autoload.php';

$handler = function(array $asgiEnv, $requestId) {
    return [200, 'OK', [], '<html><body><h1>Hello, world.</h1></body></html>'];
    /*
    if ($asgiEnv['REQUEST_URI'] == '/sendfile') {
        return [200, 'OK', ['X-Sendfile' => __DIR__ .'/www/test.txt'], NULL];
    } elseif ($asgiEnv['REQUEST_URI'] == '/favicon.ico') {
        return [404, 'Not Found', [], '<h1>404 Not Found</h1>'];
    } else {
        return [200, 'OK', [], '<html><body><h1>Hello, world.</h1></body></html>'];
    }
    */
};

$config = [
    /*
    'globals' => [
        
        'opts' => [
            'maxConnections'            => 0,
            'maxRequestsPerSession'     => 0,
            'idleConnectionTimeout'     => 15,
            'maxStartLineSize'          => 2048,
            'maxHeadersSize'            => 8192,
            'maxEntityBodySize'         => 2097152,
            'tempEntityDir'             => NULL,
            'defaultContentType'        => 'text/html',
            'autoReasonPhrase'          => TRUE,
            'cryptoHandshakeTimeout'    => 3,
            'ipv6Mode'                  => FALSE
        ],
        
        'tls'   => [
            '*:1500' => [
                'localCertFile'         => dirname(__DIR__) . '/mycert.pem',
                'certPassphrase'        => '42 is not a legitimate passphrase'
            ]
        ],
        
        'mods'  => [
            // Any mod you want applied to all hosts should be specified here.
            // If a mod exists with the same key in a host config block it will
            // override the global instance specified here.
        ]
    ],
    */
    // --- ALL OTHER KEYS ARE CONSIDERED HOST CONTAINERS ---
    /*
    'myHost.secure' => [
        'listen'    => '*:1500', // <-- we specified a TLS definition in the "globals" section
        'name'      => 'aerys', // <--- optional
        'handler'   => $handler
    ],
    */
    
    'myHost.static' => [
        'listen'    => '*:1337', // <-- we specified a TLS definition in the "globals" section
        'name'      => 'aerys', // <--- optional
        'handler'   => new Aerys\Handlers\Filesys(__DIR__ . '/www')
    ],
    
    /*
    'myHost.insecure' => [
        'listen'    => '*:1337',
        'name'      => 'aerys', // <--- optional
        'handler'   => $handler,
        'mods'      => [
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
                    60 => 200, // send a 429 if client has made > 200 requests in the past 60 seconds
                ]
            ],
            
            'mod.errorpages' => [
                404 => [__DIR__ .'/errorpages/404.html', 'text/html'],
            ],
            
            // All sendfile keys are optional; these are the defaults:
            'mod.sendfile' => [
                'docRoot' => '/',
                'staleAfter' => 60,
                'types' => [],
                'eTagMode' => Aerys\Handlers\Filesys::ETAG_ALL
            ],
            */
            
            /*
            // --- INCOMPLETE MODS ---
            
            // @todo mod.redirect
            // @todo mod.rewrite
            // @todo mod.websocket
            // @todo mod.block
            
            'mod.block' => [
                'ipProxyHeader' => NULL, // use the specified header instead of the raw IP if available
                'block' => ['*'], // specific IPs or IP ranges
                'allow' => ['127.0.0.1'] // specific IPs or IP ranges
            ],
        ]
    ]
    */
];

$server = (new ServerFactory)->createServer($config)->listen();




