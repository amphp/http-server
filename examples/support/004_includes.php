<?php

use Amp\Success;
use Amp\Failure;

// In reality we'd use non-blocking libraries here to return promises.
// For the purpose of this example we'll just wait for a moment then
// yield the final result at some point in the future.

/**
 * Multiply $x and $y after 25 milliseconds
 */
function asyncMultiply($x, $y) {
    yield 'pause' => 25;
    yield ($x*$y);
}

/**
 * Subtract $y from $x after 25 milliseconds
 */
function asyncSubtract($x, $y) {
    yield 'pause' => 25;
    yield ($x-$y);
}

/**
 * Fail after 25 milliseconds
 */
function asyncFailure() {
    yield 'pause' => 25;
    throw new \RuntimeException('Example async failure');
}


function gen1($request) {
    $result = (yield asyncMultiply(6, 7));
    yield 'body' => "<html><body><h1>gen1: {$result}</h1></body></html>";
}

function gen2($request) {
    $async42 = (yield asyncMultiply(6, 7));
    $async17 = (yield asyncSubtract($async42, 25));
    $async34 = (yield asyncMultiply($async17, 2));

    yield 'body' => "<html><body><h1>gen2: {$async34}</h1></body></html>";
}

function gen3($request) {
    extract(yield [
        'result1' => asyncMultiply(6, 7),
        'result2' => asyncSubtract(5, 3),
        'result3' => asyncMultiply(5, 5)
    ]);

    yield 'body' => "<html><body><h1>{$result1} | {$result2} | {$result3} (gen3)</h1></body></html>";
}

function gen4($request) {
    list($result1, $result2, $result3) = (yield [
        asyncMultiply(6, 7),
        asyncSubtract(5, 3),
        asyncMultiply(5, 5)
    ]);

    yield 'body' => "<html><body><h1>{$result1} | {$result2} | {$result3} (gen4)</h1></body></html>";
}

function gen5($request) {
    yield 'status' => 200;
    yield 'reason' => 'OK';
    yield 'header' => 'X-My-Header: hello world';
    yield 'header' => 'X-My-Other-Header: hello again!';
    yield 'body' => '<html><body><h1>Hello, world (gen5).</h1></body></html>';
}

function gen6($request) {
    yield 'status' => 200;
    yield 'body' => '<html><body><h1>Hello, world (gen6).</h1></body></html>';
}

function gen7($request) {
    try {
        $result = (yield asyncFailure());
        yield 'body' => "<html><body><h1>You'll never see this</h1></body></html>";
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
    yield 'body' => '<html><body><h1>Hello, world (gen8).</h1></body></html>';
}

function gen9($request) {
    try {
        // Start output
        yield 'body' => '<html><body><h1>Click your browser\'s STOP button now ...</h1>';

        // Pause for the specified number of milliseconds. Click your browser's stop
        // button during this pause to see the } catch() { result in your console.
        yield 'pause' => 5000;

        // If the client never aborts we'll finish the HTML message ...
        yield 'body' => 'You were supposed to click stop!</body></html>';

    } catch (Aerys\ClientGoneException $e) {
        echo "Woot! we caught the client abort!\n";
        // Do other stuff after the client has disconnected here.
    }
}

function gen10($request) {
    yield;
    throw new RuntimeException('test exception');
}

function gen11($request) {
    yield "body" => "<html><body><p>start output</p>";
    throw new RuntimeException('test exception');
}

function myFallbackResponder($request) {
    return '<html><body><h1>Hello, world (fallback).</h1></body></html>';
}
