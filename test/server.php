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
        return [200, ['X-Sendfile' => __DIR__ .'/www/test.txt'], NULL];
    } elseif ($asgiEnv['REQUEST_URI'] == '/favicon.ico') {
        return [404, [], '<h1>404 Not Found</h1>'];
    } else {
        return [200, [], '<html><body><h1>Hello, world.</h1></body></html>'];
    }
};

$config = [
    'aerys.globals'   => [
        'maxConnections'                => 0,
        'maxRequestsPerSession'         => 0,
        'idleConnectionTimeout'         => 15,
        'maxStartLineSize'              => 2048,
        'maxHeadersSize'                => 8192,
        'maxEntityBodySize'             => 2097152,
        'tempEntityDir'                 => NULL,
        'cryptoHandshakeTimeout'        => 3,
        'defaultContentType'            => 'text/html',
        
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
    
    
    
    'host.dynamic' => [
        'listen'    => '*:1337',
        'name'      => 'aerys',
        'handler'   => $handler,
        
        // forthcoming mod configs ...
        /*
        'mod.sendfile' => [
            'docRoot' => '/',
            'indexes' => ['index.html', 'index.htm'],
            'staleAfter' => 60,
            'types' => [],
            'eTagMode' => Aerys\Handlers\Filesys::ETAG_ALL
        ],
        */
        //'mod.limit' => [ // 429 Too Many Requests
        //   '*' => [$maxRequestsPerIp, $timePeriodInSeconds],
        //    '/some/specific/resource' => [$maxRequestsPerIp, $timePeriodInSeconds],
        //    '/some/dir/*' => [$maxRequestsPerIp, $timePeriodInSeconds],
        //],
        
        'mod.log'   =>  [
            'flushSize' => 0,
            'logs' => [
                //__DIR__ . '/log/access.log' => 'combined',
                'php://stdout' => 'common',
            ]
        ],
        
        // @todo mod.redirect
        // @todo mod.rewrite
        // @todo mod.websocket
    ],
    
    /*
    'host.static' => [
        'listen'    => '*:1337',
        'name'      => 'static.aerys',
        'handler'   => new Aerys\Handlers\Filesys(__DIR__ . '/www')
    ],
    */
];

//(new ServerFactory)->createServer($config)->listen();

$server = (new ServerFactory)->createServer($config);

$log = new Aerys\Mods\Log($server);
$log->configure($config['host.dynamic']['mod.log']);
$server->registerMod($log, 'aerys:1337');


/*
$sendFile = new Aerys\Mods\SendFile($server, new Aerys\Handlers\Filesys('/'));
$server->registerMod($sendFile, 'aerys:1337');
*/

$server->listen();


















