<?php

/**
 * apm_example_handler.php
 * 
 * This file provides the handler used in the `apm_process_manager.php` server example. Please refer
 * to the documentation inside that file for more information.
 */

function main(array $asgiEnv) {
    $status = 200;
    $reason = 'OK';
    $headers = [];
    $body = '<html><body><h1>Hello, world.</h1></body></html>';
    
    return [$status, $reason, $headers, $body];
}

