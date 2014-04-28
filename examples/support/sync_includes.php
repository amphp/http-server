<?php

function myThreadRoute($request) {
    $body[] = "<html><body><h1>Woot, I'm in a Thread!</h1>";
    $body[] = "<p>You can do synchronous/blocking things here!</p>";
    $body[] = "<ul><li><a href=\"/non-blocking\">/non-blocking</a></li>";
    $body[] = "<li><a href=\"/streaming\">/streaming</a></li>";
    $body[] = "<li><a href=\"/custom\">/custom</a></li>";
    $body[] = "<li><a href=\"/anything-else\">/anything-else</a></li></ul><hr/>";
    $body[] = "<pre>" . print_r($request, TRUE) . "</pre></body></html>";
    
    return implode("\n", $body);
}

function myStreamingThreadRoute($request) {
    return function() {
        echo "<html>\n<body>\n<h1>Stream output in a response body</h1>\n";
        for ($i=0; $i<5; $i++) {
            echo "Line {$i}<br/>\n";
        }
        echo "</body>\n</html>";
    };
}

function myCustomThreadResponseRoute($request) {
    $vars = "<pre>" . print_r($request, TRUE) . "</pre>";
    $body = "<html><body><h1>Custom response route</h1><hr/>{$vars}</body></html>";

    return (new Aerys\Response)
        ->setStatus(200)
        ->setReason('OK')
        ->setHeader('My-Header', 'some value')
        ->setBody($body);
}

function myNonBlockingRoute($request) {
    $body[] = "<html><body><h1>Standard Route Handler (non-blocking)</h1>";
    $body[] = "<p>Any disk or network IO performed here must not block</p><hr/>";
    $body[] = "<pre>" . print_r($request, TRUE) . "</pre></body></html>";
    
    return implode("\n", $body);
}

function myFallbackResponder($request) {
    $body[] = "<html><body><h1>Fallback (non-blocking)</h1>";
    $body[] = "<p>Callables registered with App::addResponder() are used as a fallback if";
    $body[] = "no routes were matched</p><hr/>";
    $body[] = "<pre>" . print_r($request, TRUE) . "</pre></body></html>";
    
    return implode("\n", $body);
}
