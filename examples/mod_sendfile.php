<?php

/**
 * Often we wish to send static files in response to requests but also perform dynamic functions
 * like user authorization, analysis, logging, etc. Aerys allows us to assign a stream resource as
 * the entity body, but this is somewhat primitive because you don't get built-in support for things
 * like caching headers, byte-range request fulfillment, etag/last-modified support, etc.
 * 
 * A much better solution is to enable ModSendFile and pass a single header to get all the benefits
 * of a full-blown static file server while still performing dynamic tasks. The example below
 * demonstrates how to assign an `X-Sendfile` header (case-insensitive). Requests to the resource
 * at "/example" will stream a static file of our choosing. All others receive a basic "Hello World"
 * response.
 */

$myApp = function(array $asgiEnv, $requestId) {
    // Do some dynamic validation or whatever based on the request environment here. Once you
    // know if/what you want to send, respond using a standard ASGI response specifying the
    // `X-Sendfile` header. Paths in this header are resolved relative to the docRoot setting
    // assigned in the mod's configuration array.
    
    if ($asgiEnv['REQUEST_URI'] === '/example') {
        $headers = [
            'X-Sendfile: example.txt' // a leading slash in the path makes no difference
        ];
    } else {
        $headers = [];
    }
    
    return [200, 'OK', $headers, "<html><body><h1>Hello, world.</h1></body></html>"];
};

$config = [
    'my-app' => [
        'listenOn' => '*:1338',
        'application' => $myApp,
        'mods' => [
            'send-file' => [
                'docRoot' => __DIR__ . '/support/sendfile', // *required (path to your static files)
            ],
        ]
    ]
];
