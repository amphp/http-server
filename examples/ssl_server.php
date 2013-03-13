<?php

/**
 * examples/ssl_server.php
 * 
 * TLS/SSL settings are defined on a per INTERFACE:PORT basis. Enabling crypto on a given interface
 * will enable it for ALL hosts set to listen on that INTERFACE:PORT. For this reason, TLS settings
 * are applied inside the "globals" config section under the "tls" key.
 * 
 * $ php ssl_server.php
 * 
 * Once the server has started, request https://127.0.0.1:1443/ in your browser or client of choice.
 * _IMPORTANT:_ Make sure to use the HTTPS scheme and not HTTP (duh!)
 */

use Aerys\Http\Config\ServerConfigurator;

require dirname(__DIR__) . '/autoload.php';

date_default_timezone_set('GMT');

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
    'globals' => [
        'tls' => [
            '127.0.0.1:1443' => [
                'localCertFile'  => __DIR__ . '/support_files/ssl_example_cert.pem',
                'certPassphrase' => '42 is not a legitimate passphrase'
            ]
        ]
    ],
    'mySslServer'       => [
        'listenOn'      => '127.0.0.1:1443',
        'application'   => $myApp
    ]
];

(new ServerConfigurator)->createServer($config)->listen();

