<?php

// To run, execute this script and request https://localhost:1443/ in your browser

use Aerys\Config\Configurator;

require dirname(__DIR__) . '/autoload.php';

$myApp = function(array $asgiEnv) {
    $body = '<html><body style="font-family: Sans-Serif;"><h1>Hello, world.</h1>';
    $body.= '<h3>Your request environment is ...</h3>';
    $body.= '<p style="color:red;">Note the <strong>ASGI_URL_SCHEME</strong> (https)</p>';
    $body.= '<pre>' . print_r($asgiEnv, TRUE) . '</pre>';
    $body.= '</body></html>';
    
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$config = [
    'mySslServer'       => [
        'listenOn'      => '127.0.0.1:1443',
        'name'          => 'localhost',
        'application'   => $myApp,
        
        'tls'           => [
            'local_cert'            => __DIR__ . '/support_files/localhost_cert.pem',
            'passphrase'            => '42 is not a legitimate passphrase',
            
            /* -------- OPTIONAL SETTINGS BEYOND THIS POINT ---------- */
            
            'allow_self_signed'     => NULL,   // TRUE,
            'verify_peer'           => NULL,   // FALSE,
            'ciphers'               => NULL,   // 'RC4-SHA:HIGH:!MD5:!aNULL:!EDH',
            'disable_compression'   => NULL,   // TRUE,
            'cafile'                => NULL,   // NULL,
            'capath'                => NULL,   // NULL
        ]
    ]
];

(new Configurator)->createServer($config)->start();

