<?php

// To run, execute this script and request https://127.0.0.1:1443/ in your browser

use Aerys\Config\Bootstrapper;

require dirname(__DIR__) . '/autoload.php';

$myApp = function(array $asgiEnv) {
    $body = '<html><body style="font-family: Sans-Serif;"><h1>Hello, world.</h1>';
    $body.= '<h3>Your request environment is ...</h3>';
    $body.= '<p style="color:red;">Notice the <strong>ASGI_URL_SCHEME</strong> key (https)</p>';
    $body.= '<pre>' . print_r($asgiEnv, TRUE) . '</pre>';
    $body.= '</body></html>';
    
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

(new Bootstrapper)->createServer([[
    'listenOn'      => '*:1443',
    'application'   => $myApp,
    'tls'           => [
        'local_cert'            => __DIR__ . '/support/localhost_cert.pem',
        'passphrase'            => '42 is not a legitimate passphrase',
        
        /* -------- OPTIONAL SETTINGS BEYOND THIS POINT ---------- */
        
        'allow_self_signed'     => NULL,   // TRUE,
        'verify_peer'           => NULL,   // FALSE,
        'ciphers'               => NULL,   // 'RC4-SHA:HIGH:!MD5:!aNULL:!EDH',
        'disable_compression'   => NULL,   // TRUE,
        'cafile'                => NULL,   // NULL,
        'capath'                => NULL,   // NULL
    ]
]])->start();

