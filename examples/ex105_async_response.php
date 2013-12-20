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

// ------------- Fake non-blocking callables ----------------------

function asyncMultiply($x, $y, callable $onCompletion) {
    $multiplicationResult = $x*$y; // <-- in reality we'd use a non-blocking lib to do something here
    $onCompletion($multiplicationResult); // <-- $multiplicationResult is returned to our generator
}

function asyncSubtract($x, $y, callable $onCompletion) {
    $subtractionResult = $x - $y;
    $onCompletion($subtractionResult);
}

function nestedAsync($x, $y, callable $onCompletion) {
    $result1 = (yield 'asyncMultiply' => [$x, $y]);
    $result2 = (yield 'asyncMultiply' => [$result1, 5]);
    
    $onCompletion($result2);
}

// ------------- Our actual response handlers ---------------------

function sexyAsyncResponder($request) {
    $x = 6; $y = 7;
    $multiplicationResult = (yield 'asyncMultiply' => [$x, $y]);

    yield "<html><body><h1>Brevity FTW ({$multiplicationResult})!</h1></body></html>";
}

function multiAsyncResponder($request) {
    $x = 6; $y = 7;
    $result1 = (yield 'asyncMultiply' => [$x, $y]);
    $result2 = (yield 'asyncSubtract' => [$result1, 25]);
    $result3 = (yield 'asyncMultiply' => [$result2, 2]);

    yield "<html><body><h1>Async! All of the things ({$result3})!</h1></body></html>";
}

function groupAsyncResponder($request) {
    extract(yield [
        'result1' => ['asyncMultiply', 6, 7],
        'result2' => ['asyncSubtract', 5, 3],
        'result3' => ['asyncMultiply', 5, 5]
    ]);

    yield "<html><body><h1>{$result1} | {$result2} | {$result3}</h1></body></html>";
}

function anotherGroupAsyncResponder($request) {
    list($result1, $result2, $result3) = (yield [
        ['asyncMultiply', 6, 7],
        ['asyncSubtract', 5, 3],
        ['asyncMultiply', 5, 5]
    ]);

    yield "<html><body><h1>{$result1} | {$result2} | {$result3}</h1></body></html>";
}

// ------------- Our app configuration ---------------------

$myApp = (new App)
    ->setPort(1338)
    ->addRoute('GET', '/', 'sexyAsyncResponder')
    ->addRoute('GET', '/multi', 'multiAsyncResponder')
    ->addRoute('GET', '/group', 'groupAsyncResponder')
    ->addRoute('GET', '/group2', 'anotherGroupAsyncResponder')
;
