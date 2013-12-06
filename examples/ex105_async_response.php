<?php

/**
 * @TODO
 *
 * To run:
 * $ bin/aerys -c examples/ex105_async_response.php
 *
 * Once started, load http://127.0.0.1:1338/ or http://localhost:1338/ in your browser.
 */

use Aerys\Framework\App;

require_once __DIR__ . '/../vendor/autoload.php';

// This function is a placeholder for the example and isn't actually doing anything asynchronous. In
// a real situation your application will be invoking a non-blocking library of some sort instead
// of this function and passing it a callback to be executed when the async lib finishes its work.
function multiplyByTwoAsync($arg, callable $onCompletion) {
    $onCompletion($arg*2); // <-- [$arg*2] returned to your generator
}

function myAsyncRequestResponder($asgiEnv) {
    $arg = 1;

    list($arg) = (yield function(callable $onCompletion) use ($arg) {
        multiplyByTwoAsync($arg, $onCompletion);
    });

    list($arg) = (yield function(callable $onCompletion) use ($arg) {
        multiplyByTwoAsync($arg, $onCompletion);
    });

    yield "<html><body><h1>Woot! Generated {$arg}!</h1></body></html>";
};

$myApp = (new App)->setPort(1338)->addUserResponder('myAsyncRequestResponder');
