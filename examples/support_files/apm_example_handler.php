<?php

/**
 * apm_example_handler.php
 * 
 * This file provides the handler used in the `apm_process_manager.php` server example. The front
 * controller file for process manager applications must specify a `main()` function that returns
 * a callable to handle client requests. Please refer to the documentation inside that file for 
 * more information.
 */

class MyApp {
    function handleRequest(array $asgiEnv) {
        $status = 200;
        $reason = 'OK';
        $headers = [];
        
        $body = '<html><body style="font-family: Sans-Serif;"><h1>Hello, world.</h1>';
        $body.= '<h3>Your request environment is ...</h3>';
        $body.= '<pre>' . print_r($asgiEnv, TRUE) . '</pre>';
        $body.= '</body></html>';
        
        return [$status, $reason, $headers, $body];
    }
}

function main() {
    return [new MyApp, 'handleRequest'];
}
