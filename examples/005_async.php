<?php

/**
 * @TODO
 *
 * To run:
 * $ bin/aerys -c examples/005_async.php
 *
 * Once started, load http://127.0.0.1:1338/ in your browser.
 */

use Aerys\Start\App, Alert\Success, Alert\Future;

require __DIR__ . '/../src/bootstrap.php';

// ------ Any library that returns an Alert\Future instance -------

// In reality we'd use non-blocking libraries here to return futures.
// For the purpose of this example we'll just return Futures whose
// values are already fulfilled.

function asyncMultiply($x, $y) {
    return new Success($x*$y);
}

function asyncSubtract($x, $y) {
    return new Success($x-$y);
}

// ------------- Our actual response handlers ---------------------

function asyncResponder($request) {
    $result = (yield asyncMultiply(6, 7));
    yield "<html><body><h1>Async result: {$result}</h1></body></html>";
}

function multiAsyncResponder($request) {
    // Execute each async operation sequentially
    $async42 = (yield asyncMultiply(6, 7));
    $async17 = (yield asyncSubtract($async42, 25));
    $async34 = (yield asyncMultiply($async17, 2));

    yield "<html><body><h1>Async! All of the things ({$async34})!</h1></body></html>";
}

function group1AsyncResponder($request) {
    // Execute all three async operations in parallel!
    extract(yield [
        'result1' => asyncMultiply(6, 7),
        'result2' => asyncSubtract(5, 3),
        'result3' => asyncMultiply(5, 5)
    ]);

    yield "<html><body><h1>{$result1} | {$result2} | {$result3}</h1></body></html>";
}

function group2AsyncResponder($request) {
    // Execute all three async operations in parallel!
    list($result1, $result2, $result3) = (yield [
        asyncMultiply(6, 7),
        asyncSubtract(5, 3),
        asyncMultiply(5, 5)
    ]);

    yield "<html><body><h1>{$result1} | {$result2} | {$result3}</h1></body></html>";
}

// ------------- Our app configuration ---------------------

$myApp = (new App)
    ->setPort(1338)
    ->addRoute('GET', '/', 'asyncResponder')
    ->addRoute('GET', '/multi', 'multiAsyncResponder')
    ->addRoute('GET', '/group1', 'group1AsyncResponder')
    ->addRoute('GET', '/group2', 'group2AsyncResponder')
;
