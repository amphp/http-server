<?php

/**
 * @TODO
 *
 * RUNNABLE EXAMPLE
 *
 * To run this example server:
 *
 * $ bin/aerys -c examples/002_strings.php
 *
 * Once started, load http://127.0.0.1:1337/ or http://localhost:1337/ in your browser.
 */

function index($request) {
    return '<html><body><h1>Index</h1></body></html>';
}

function page2($request) {
    return '<html><body><h1>Page 2</h1></body></html>';
}

function page3($request) {
    return '<html><body><h1>Page 3</h1></body></html>';
}

function myCatchAllResponder($request) {
    return '<html><body><h1>Catch-All Responder</h1></body></html>';
}

$myHost = (new Aerys\Host)
    ->setPort(1337)
    ->setAddress('*')
    ->addRoute('GET', '/', 'index')
    ->addRoute('GET', '/page2', 'page2')
    ->addRoute('GET', '/page3', 'page3')
    ->addResponder('myCatchAllResponder')
;
