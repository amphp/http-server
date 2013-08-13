<?php

/**
 * One of the niftier aspects of the ASGI specification is the allowance of PHP Iterator instances
 * as response bodies. If presented with an Iterator body Aerys will stream its contents to the
 * client until Iterator::valid() returns FALSE. This works both for HTTP/1.0 and HTTP/1.1 clients
 * and Aerys automatically handles the necessary headers and chunking (for 1.1 responses) as needed.
 * Note that *seekable* stream resources may also be specified as response entity bodies and will
 * be streamed to the client as well (this is how the static file DocRootHandler works). Of course,
 * Iterators are infinitely more legit.
 * 
 * Using an Iterator if you know the response content ahead of time is pointless. This example
 * demonstrates wrapping the results of asynchronous body generation so that headers can be sent to
 * the client immediately instead of waiting for your application to determine the entire response
 * body. The body can then be streamed to the client in real-time as it's generated asynchronously.
 * 
 * This example returns a 200 response and uses the generator (which PHP treats like an Iterator) to
 * stream the individual parts of the entity body as they become available.
 * 
 * To run this server:
 * 
 * $ php aerys.php -c="/path/to/streaming_responses.php"
 * 
 * NOTE: this example requires PHP 5.5+ because it uses a generator, but you could just as easily
 * use an instance of the Iterator interface as the response body.
 * 
 * Once started you should be able to access the application at the address http://127.0.0.1:1337/
 * in your browser.
 */

$body = function() {
    $parts = [
        '<html><body><h1>Hello, world.</h1>',
        '<h3>Streaming Iterator chunk 1</h3>',
        NULL, // <-- Return NULL if awaiting more data (aerys will ignore it)
        '<h4>Streaming Iterator chunk 2</h4>',
        '<h5>Streaming Iterator chunk 3</h5>',
        '</body></html>'
    ];
    
    while (FALSE !== $part = current($parts)) {
        next($parts);
        yield $part;
    }
};

$myApp = function(array $asgiEnv) use ($body) {
    return [$status = 200, $reason = 'OK', $headers = [], $body()];
};

$config = [
    'my-streaming-app' => [
        'listenOn' => '*:1337',
        'application' => $myApp
    ]
];
