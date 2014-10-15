<?php

/**
 * @TODO
 *
 * RUNNABLE EXAMPLE
 *
 * To run this example server:
 *
 * $ bin/aerys -c examples/003_arrays.php
 *
 * Once started, load http://127.0.0.1:1337/ or http://localhost:1337/ in your browser.
 */

function arr1($request) {
    return [
        'status' => 200,
        'reason' => 'OK',
        'header' => 'X-My-Header: hello world',
        'body'   => '<html><body><h1>Hello, world (arr1).</h1></body></html>'
    ];
}

function arr2($request) {
    return [
        'body'   => '<html><body><h1>Hello, world (arr2).</h1></body></html>',
        'header' => [
            'X-My-Header-1: hello world',
            'X-My-Header-2: hello again',
        ],
    ];
}

function myCatchAllResponder($request) {
    return '<html><body><h1>Catch-All Responder</h1></body></html>';
}


$myHost = (new Aerys\Host)
    ->setPort(1337)
    ->setAddress('*')
    ->addRoute('GET', '/arr1', 'arr1')
    ->addRoute('GET', '/arr2', 'arr2')
    ->addResponder('myCatchAllResponder')
;
