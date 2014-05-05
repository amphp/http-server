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

use Aerys\HostConfig;

/* --- Our long-polling responder --------------------------------------------------------------- */

function longPoller($request) {
    $msDelay = 1000;
    while (TRUE) {
        // Send the current date to the client. Yielding a string results in that data being
        // written to the client.
        yield sprintf("The current time is %s\n", date('r'));

        // Yielding an integer tells the server to wait $msDelay milliseconds before giving
        // control back to our generator to resume the loop.
        yield $msDelay;
    }
}

/* --- http://localhost:1337/ or http://127.0.0.1:1337/  (all IPv4 interfaces) ------------------ */

$myHost = (new HostConfig)
    ->setPort(1337)
    ->setAddress('*')
    ->addRoute('GET', '/poll', 'longPoller')
    ->addResponder(function($request) {
        return '<html><body><h1>Fallback Responder</h1></body></html>';
    })
;
