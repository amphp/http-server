<?php

/**
 * examples/async_response.php
 * 
 * We know that the ASGI specification requires servers to invoke application callables with the
 * ASGI environment array. The application must then respond with the appropriate response array.
 * This basic IO approach works fine until you realize that Aerys executes inside a non-blocking
 * event loop. We also know that PHP is not a threaded language. Uh oh. If our application code
 * blocks for every response it only takes one slow database query (or any other IO-bound operation)
 * to hose performance for the entire server. We're totally foobar'd.
 * 
 * Fortunately, we can invoke functions asynchronously using the Amp library's language-agnostic
 * async dispatcher. Currently, asynchronous adapters exist to call both PHP and Python
 * functions asynchronously, though any language may be used with an appropriate adapter.
 * 
 * From here it's little more than a hop, skip and a jump to generating complex responses
 * asynchronously in our non-blocking Aerys server. To see implementation details please peruse
 * the example async request handler located at ./examples/support_files/MyAsyncRequestHandler.php.
 * 
 * To run the example, execute this script and request http://127.0.0.1:1337/ in your browser
 */

use Aerys\Config\Configurator,
    Aerys\Config\AsyncClassLauncher;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/async_response/ExampleAsyncApp.php';

(new Configurator)->createServer([[
    'listenOn'    => '*:1337',
    'application' => new AsyncClassLauncher([
        'handlerClass'  => 'ExampleAsyncApp', // A handler class typehinting Amp\Async\Dispatcher in its constructor
        'functions'     => __DIR__ . '/support/async_response/example_async_functions.php', // A file declaring functions you want to call asynchronously
        
        // --- OPTIONAL VALUES BELOW: we only declare these here to show that they exist --- //
        
        'binaryCmd'     => NULL, // Defaults to the value of the PHP_BINARY constant
        'workerCmd'     => NULL, // The AMP worker script. Don't specify unless you know what you're doing.
        'processes'     => NULL, // How many worker processes to use in the dispatcher pool (defaults to 8)
        'callTimeout'   => NULL, // Return an error if an async call doesn't come back in X seconds (defaults to 30)
    ])
]])->start();

