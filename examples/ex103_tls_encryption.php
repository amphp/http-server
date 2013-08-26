<?php

/**
 * TLS encryption is *important*; it should be easy to implement. To encrypt your Aerys web apps
 * simply call `App::setEncryption` with an array specifying the hard path to your certificate (in
 * .PEM format) and the passphrase associated with the cert. Note that you should also usually set
 * the port for encrypted apps to 443 as this is the default for encrypted HTTP communications.
 * 
 * Note that the encryption specification works the same regardless of what your app serves. This
 * means that, for example, the same approach demonstrated below also works to encrypt your
 * websocket endpoints.
 * 
 * In the example below we go one step further and add a second application to listen on port 80
 * that redirects ALL unencrypted traffic to the equivalent encrypted address on port 443.
 * 
 * To run:
 * $ bin/aerys -a examples/ex103_tls_encryption.php
 * 
 * Once started, load https://127.0.0.1/ in your browser. Note that your browser will not trust the
 * certificate and ask you if you're sure you want to continue. This happens because the cert we
 * specify was manually generated specifically for this example -- it's not signed by a major
 * Certificate Authority (CA). As such browsers won't trust it by default. It's 100% safe to click
 * through the security warning.
 */

require __DIR__ . '/../vendor/autoload.php';

$encryptionSettings = [
    'local_cert' => __DIR__ . '/../examples/support/tls_cert.pem',  // required
    'passphrase' => '42 is not a legitimate passphrase',            // required
    
    // -------- Optional Settings (defaults shown) ---------- //
    
    'allow_self_signed'     => FALSE,
    'verify_peer'           => FALSE,
    'ciphers'               => 'RC4-SHA:HIGH:!MD5:!aNULL:!EDH',
    'disable_compression'   => TRUE,
    'cafile'                => NULL,
    'capath'                => NULL
];

$encryptedApp = (new Aerys\Framework\App)
    ->setPort(443)
    ->setEncryption($encryptionSettings)
    ->setDocumentRoot(__DIR__ . '/support/docroot');

$redirectResponder = function($asgiEnv) {
    $status = 301;
    $reason = 'Moved Permanently';
    $headers = [
        'Location: https://127.0.0.1' . $asgiEnv['REQUEST_URI']
    ];
    $body = '<html><body>All requests require encryption</body></html>';
    
    return [$status, $reason, $headers, $body];
};

$regularApp = (new Aerys\Framework\App)->setPort(80)->addUserResponder($redirectResponder);

