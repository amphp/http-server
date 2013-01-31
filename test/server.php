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
    if ($asgiEnv['REQUEST_URI'] !== '/favicon.ico') {
        $status = 200;
        $body = '<html><body><h1>Hello, world.</h1></body></html>';
        $headers = [
            'Content-Length' => strlen($body)
        ];
        
        $response = [$status, $headers, $body];
    } else {
        $body = '<h1>404 Not Found</h1>';
        $headers = [
            'Content-Length' => strlen($body)
        ];
        
        $response = [404, $headers, $body];
    }
    
    return $response;
};


$redirectHandler = function(array $asgiEnv) {
    $body = '<html><body>All traffic must be secure! Use https://aerys instead!</body></html>';
    $headers = [
        'Connection' => 'close',
        'Location' => 'https://aerys' . $asgiEnv['REQUEST_URI'],
        'Content-Length' => strlen($body)
    ];
    
    return [301, $headers, $body];
};

$config = [
    'aerys.globals'   => [
        'maxSimultaneousConnections'    => 0,
        'maxRequestsPerSession'         => 0,
        'idleConnectionTimeout'         => 15,
        'maxStartLineSize'              => 2048,
        'maxHeadersSize'                => 8192,
        'maxEntityBodySize'             => 2097152,
        'tempEntityDir'                 => NULL,
        'cryptoHandshakeTimeout'        => 3,
        'defaultContentType'            => 'text/html',
        'exposeServerToken'             => TRUE,
        
        'tlsDefinitions'                => [
            '*:443' => [
                'localCertFile'     => dirname(__DIR__) . '/mycert.pem',
                'certPassphrase'    => '42 is not a legitimate passphrase'
            ],
        ]
    ],
    
    // --- ANY OTHER KEYS ARE CONSIDERED HOST CONTAINERS ---
    /*
    'host.secure' => [
        'listen'    => '*:443',
        'name'      => 'aerys',
        'handler'   => $handler
    ],
    */
    
    'host.static' => [
        'listen'    => '*:1337',
        'name'      => 'static.aerys',
        'handler'   => new Aerys\Handlers\Filesys(__DIR__ . '/www')
    ],
    
    /*
    'host.dynamic' => [
        'listen'    => '*:1337',
        'name'      => 'aerys',
        'handler'   => $redirectHandler,
        
        
        // forthcoming mod configs ...
        
        //'mod.limit' => [ // 429 Too Many Requests
        //   '*' => [$maxRequestsPerIp, $timePeriodInSeconds],
        //    '/some/specific/resource' => [$maxRequestsPerIp, $timePeriodInSeconds],
        //    '/some/dir/*' => [$maxRequestsPerIp, $timePeriodInSeconds],
        //],
        
        //'mod.log'   =>  [
        //    '/path/to/log/file.txt' => 'format string (or one of the available default format names)'
        //],
        
        // @todo mod.redirect
        // @todo mod.rewrite
        // @todo mod.websocket
    ],
    */
];

(new ServerFactory)->createServer($config)->listen();


