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
    if ($asgiEnv['REQUEST_URI'] == '/sendfile') {
        return [200, 'OK', ['X-Sendfile' => __DIR__ .'/www/test.txt'], NULL];
    } elseif ($asgiEnv['REQUEST_URI'] == '/favicon.ico') {
        return [404, 'Not Found', [], '<h1>404 Not Found</h1>'];
    } else {
        return [200, 'OK', [], '<html><body><h1>Hello, world.</h1></body></html>'];
    }
};

$config = [
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
            // If a mod using the same key exists in the host config it will
            // override the global instance specified in this block.
        ]
    ],
    
    // --- ALL OTHER KEYS ARE CONSIDERED HOST CONTAINERS ---
    
    'myHost.secure' => [
        'listen'    => '*:1500', // <-- we specified a TLS definition in the "globals" section
        'name'      => 'aerys',
        'handler'   => $handler
    ],
    
    'myHost.insecure' => [
        'listen'    => '*:1337',
        'name'      => 'aerys',
        'handler'   => $handler,
        'mods'      => [
            
            /*
            'mod.log'   =>  [
                'logs' => [
                    'php://stdout' => 'common'
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
            
            // @todo mod.limit
            // @todo mod.redirect
            // @todo mod.rewrite
            // @todo mod.websocket
            
            'mod.limit' => [
                'ipProxyHeader' => NULL, // use the specified header instead of the raw IP if available (helpful for proxies)
                'block' => ['*'], // specific IP or range of IPs
                'allow' => ['127.0.0.1'], // specific IP or range of IPs
                'rateLimits' => [ // (429 Too Many Requests + Connection: close)
                    ['period' => 60, 'limit' => 100],
                    ['period' => 3600, 'limit' => 2500],
                    ['period' => 86400, 'limit' => 5000, 'location' => '/some/site/path/*'] // only applies if URI matches path
                ]
            ],
            */
        ]
    ]
];

$server = (new ServerFactory)->createServer($config)->listen();




