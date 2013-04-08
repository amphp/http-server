<?php

/**
 * examples/ssl_server.php
 * 
 * $ php ssl_server.php
 * 
 * Once the server has started, request https://localhost:1443/ in your browser or client of choice.
 * IMPORTANT: Make sure to use the HTTPS scheme and not HTTP!
 */

use Aerys\Config\Configurator;

require dirname(__DIR__) . '/autoload.php';

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

$myApp = function(array $asgiEnv) {
    $status = 200;
    $reason = 'OK';
    $headers = [];
    
    $body = '<html><body style="font-family: Sans-Serif;"><h1>Hello, world.</h1>';
    $body.= '<h3>Your request environment is ...</h3>';
    $body.= '<p style="color:red;">Note the <strong>ASGI_URL_SCHEME</strong> (https)</p>';
    $body.= '<pre>' . print_r($asgiEnv, TRUE) . '</pre>';
    $body.= '</body></html>';
    
    return [$status, $reason, $headers, $body];
};

$config = [
    'mySslServer'       => [
        'listenOn'      => '127.0.0.1:1443',
        'name'          => 'localhost',
        'application'   => $myApp,
        
        'tls'           => [
            'pemCertFile'        => __DIR__ . '/support_files/localhost_cert.pem',
            'pemCertPassphrase'  => '42 is not a legitimate passphrase',
            
            /* -------- OPTIONAL SETTINGS BEYOND THIS POINT ---------- */
            
            'allowSelfSigned'    => NULL,   // TRUE
            'verifyPeer'         => NULL,   // FALSE
            'ciphers'            => NULL,   // RC4-SHA:HIGH:!MD5:!aNULL:!EDH
            'disableCompression' => NULL,   // TRUE
            'certAuthorityFile'  => NULL,   // -
            'certAuthorityDir'   => NULL    // -
        ]
    ]
];

(new Configurator)->createServer($config)->listen();

