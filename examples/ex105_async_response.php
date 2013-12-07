<?php

/**
 * @TODO
 *
 * To run:
 * $ bin/aerys -c examples/ex105_async_response.php
 *
 * Once started, load http://127.0.0.1:1338/ in your browser.
 */

use Aerys\Framework\App;

require_once __DIR__ . '/../vendor/autoload.php';

function asyncMultiply($x, $y, callable $onCompletion) {
    $result = $x*$y; // <-- in reality we'd use a non-blocking lib to do something here
    $onCompletion($result); // <-- array($result) is returned to our generator
}

function sexyAsyncResponder($asgiEnv) {
    $x = 6; $y = 7;
    list($multiplicationResult) = (yield 'asyncMultiply' => [$x, $y]);
    yield "<html><body><h1>Chicks dig brevity ({$multiplicationResult})!</h1></body></html>";
};

function uglyAsyncResponder($asgiEnv) {
    $x = 6; $y = 7;
    list($multiplicationResult) = (yield function(callable $onCompletion) use ($x, $y) {
        asyncMultiply($x, $y, $onCompletion);
    });
    yield "<html><body><h1>Ugly, but it works ({$multiplicationResult})!</h1></body></html>";
};

$myApp = (new App)
    ->setPort(1338)
    ->addRoute('GET', '/', 'sexyAsyncResponder')
    ->addRoute('GET', '/other', 'uglyAsyncResponder');
