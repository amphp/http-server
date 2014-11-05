<?php

/**
 * LONG POLL DEMO
 *
 * @TODO
 *
 * RUNNABLE EXAMPLE
 *
 * To run this example server:
 *
 * $ bin/aerys -c examples/009_long_poll.php
 *
 * Once started, load http://127.0.0.1:1337/ or http://localhost:1337/ in your browser.
 */

date_default_timezone_set('UTC');

/* --- Our long-polling responder --------------------------------------------------------------- */

function longPoller($request) {
    $msDelay = 1000;

    yield 'body' => "<h1>longPoller()</h1>";

    while (true) {
        yield 'body' => sprintf("The current time is %s</br>\n", date('r'));

        // Yielding the "wait" key tells the server to wait $msDelay milliseconds
        // before giving control back to our generator to resume the loop.
        yield 'wait' => $msDelay;
    }
}

/* --- http://localhost:1337/ or http://127.0.0.1:1337/  (all IPv4 interfaces) ------------------ */

$myHost = (new Aerys\Host)
    ->setPort(1337)
    ->setAddress('*')
    ->addRoute('GET', '/poll', 'longPoller')
    ->addResponder(function($request) {
        return '<html><body><h1>Fallback Responder</h1></body></html>';
    })
;
