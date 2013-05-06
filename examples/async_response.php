<?php

/**
 * examples/async_response.php
 * 
 * FYI: This is the single most important example in the repository. For reals.
 * 
 * So we know that the ASGI specification requires servers to invoke application callables with
 * the ASGI environment array. The application must then respond with the appropriate response
 * array. This basic IO approach works fine until you realize that Aerys executes inside a
 * non-blocking event loop. We also know that PHP is not a threaded language. Uh oh. If our application
 * code blocks for every response it only takes one slow database query (or any other IO-bound
 * operation to hose performance for the entire server. We're totally foobar'd.
 * 
 * Fortunately, we can invoke functions asynchronously using the Amp library's language-agnostic
 * async dispatcher to avoid blocking server execution while we wait for IO-bound operations like 
 * database queries to return. Currently asynchronous adapters exist to call both PHP and Python
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

(new Configurator($injector))->createServer([[
    'listenOn'    => '*:1337',
    'application' => new AsyncClassLauncher([
        'handlerClass'  => 'ExampleAsyncApp', // A request handler class that typehints Amp\Async\Dispatcher
        'functions'     => __DIR__ . '/support/async_response/example_async_functions.php', // A file containing functions to invoke asynchronously
        'binaryCmd'     => NULL, // Defaults to the value of the PHP_BINARY constant
        'workerCmd'     => NULL, // Don't specify unless you know what you're doing
        'processes'     => NULL, // How many worker processes to use in the dispatcher pool (defaults to 8)
        'callTimeout'   => NULL, // Return an error if an async call doesn't come back in X seconds (defaults to 30)
    ])
]])->start();

