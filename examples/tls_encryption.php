<?php

/**
 * Encryption settings are assigned to a host using the "tls" key in your host's config block. The
 * two required values are "local_cert" and "passphrase" and the others are optional. This example
 * uses a self-generated certificate so browsers won't trust it by default. You can simply click
 * through any warning messages to see that the encryption works as expected.
 * 
 * Defining values in the "tls" key of a host configuration block is the same whether you're
 * encrypting static files, a dynamic application or even websocket communications. Any host on
 * which you require encryption should simply specify the relevant "tls" directive.
 * 
 * To run this example, start the server and *MAKE SURE* you use the following address in your
 * browser https://127.0.0.1:1443 (make sure to include the extra "s" in the URI scheme).
 * 
 * $ php aerys.php /path/to/examples/tls_encryption.php
 */

$myApp = function(array $asgiEnv) {
    $body = '<html><body style="font-family: Sans-Serif;">';
    $body.= '<h3>Your request environment is ...</h3>';
    $body.= '<p style="color:red;">(Notice the <strong>ASGI_URL_SCHEME</strong> key)</p>';
    $body.= '<pre>' . print_r($asgiEnv, TRUE) . '</pre>';
    $body.= '</body></html>';
    
    return [$status = 200, $reason = 'OK', $headers = [], $body];
};

$config = [
    'my-app' => [
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
    ]
];
