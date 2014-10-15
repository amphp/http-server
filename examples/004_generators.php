<?php

/**
 * @TODO
 *
 * RUNNABLE EXAMPLE
 *
 * To run this example server:
 *
 * $ bin/aerys -c examples/004_generators.php
 *
 * Once started, load http://127.0.0.1:1337/ or http://localhost:1337/ in your browser.
 */

require __DIR__ . '/support/004_includes.php';


function gen1($request) {
    $result = (yield asyncMultiply(6, 7));
    yield "<html><body><h1>gen1: {$result}</h1></body></html>";
}

function gen2($request) {
    $async42 = (yield asyncMultiply(6, 7));
    $async17 = (yield asyncSubtract($async42, 25));
    $async34 = (yield asyncMultiply($async17, 2));

    yield "<html><body><h1>gen2: {$async34}</h1></body></html>";
}

function gen3($request) {
    extract(yield [
        'result1' => asyncMultiply(6, 7),
        'result2' => asyncSubtract(5, 3),
        'result3' => asyncMultiply(5, 5)
    ]);

    yield "<html><body><h1>{$result1} | {$result2} | {$result3} (gen3)</h1></body></html>";
}

function gen4($request) {
    list($result1, $result2, $result3) = (yield [
        asyncMultiply(6, 7),
        asyncSubtract(5, 3),
        asyncMultiply(5, 5)
    ]);

    yield "<html><body><h1>{$result1} | {$result2} | {$result3} (gen4)</h1></body></html>";
}

function gen5($request) {
    yield 'status' => 200;
    yield 'reason' => 'OK';
    yield 'header' => 'X-My-Header: hello world';
    yield 'header' => 'X-My-Other-Header: hello again!';
    yield '<html><body><h1>Hello, world (gen5).</h1></body></html>';
}

function gen6($request) {
    yield 'status' => 200;
    yield '<html><body><h1>Hello, world (gen6).</h1></body></html>';
}

function gen7($request) {
    try {
        $result = (yield asyncFailure());
        yield "<html><body><h1>You'll never see this</h1></body></html>";
    } catch (\Exception $e) {
        yield 'status' => 500;
        yield 'body' => "<html><h1>500 Internal Server Error (gen7)</h1><pre>{$e}</pre>";
    }
}

function gen8($request) {
    yield 'header' => [
        'X-My-Header-1: hello world',
        'X-My-Header-2: hello again',
    ];
    yield 'header' => 'X-My-Header-3: hello three';
    yield '<html><body><h1>Hello, world (gen8).</h1></body></html>';
}

function gen9($request) {
    try {
        // Start output
        yield '<html><body><h1>Click your browser\'s STOP button now ...</h1>';

        // Pause for thirty seconds. Click your browser's stop button
        // during this pause to see the } catch() { result in your console.
        yield 'wait' => 5000;

        // If the client never aborts we'll finish the HTML message ...
        yield 'You were supposed to click stop!</body></html>';

    } catch (Aerys\ClientGoneException $e) {
        echo "Woot! we caught the client abort!\n";
        // Do other stuff after the client has disconnected here.
    }
}

function myFallbackResponder($request) {
    return '<html><body><h1>Hello, world (fallback).</h1></body></html>';
}

$myHost = (new Aerys\Host)
    ->setPort(1337)
    ->setAddress('*')
    ->addRoute('GET', '/gen1', 'gen1')
    ->addRoute('GET', '/gen2', 'gen2')
    ->addRoute('GET', '/gen3', 'gen3')
    ->addRoute('GET', '/gen4', 'gen4')
    ->addRoute('GET', '/gen5', 'gen5')
    ->addRoute('GET', '/gen6', 'gen6')
    ->addRoute('GET', '/gen7', 'gen7')
    ->addRoute('GET', '/gen8', 'gen8')
    ->addRoute('GET', '/gen9', 'gen9')
    ->addResponder('myFallbackResponder')
;
