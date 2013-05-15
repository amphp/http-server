<?php

/**
 * examples/async_response.php
 * 
 * We know that the ASGI specification requires servers to invoke application callables with the
 * ASGI environment array. The application must then respond with the appropriate response array.
 * This basic IO approach works fine until you realize that Aerys executes inside a non-blocking
 * event loop. If our application code blocks it only takes one slow database query (or any other
 * IO-bound operation) to hose performance for the entire server.
 * 
 * Fortunately, we can invoke functions asynchronously using the Amp library's language-agnostic
 * async dispatcher. Currently, asynchronous adapters exist to call both PHP and Python
 * functions asynchronously, though any language may be used with an appropriate adapter.
 * 
 * From here it's little more than a hop, skip and a jump to generating complex responses
 * asynchronously in our non-blocking Aerys server. To see implementation details please peruse
 * the example handler class used for this example.
 * 
 * To run the example, execute this script and request http://127.0.0.1:1337/ in your browser
 */

use Auryn\Provider,
    Amp\ReactorFactory,
    Amp\Async\PhpDispatcher,
    Aerys\Config\Configurator;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/async_response/ExampleAsyncApp.php';

$asyncFunctions = __DIR__ . '/support/async_response/example_async_functions.php';

$reactor = (new ReactorFactory)->select();

$injector = new Provider;
$injector->share($reactor);
$injector->alias('Amp\Reactor', get_class($reactor));

$dispatcher = new PhpDispatcher($reactor, $asyncFunctions, $workerProcesses = 8);
$dispatcher->start();
$injector->share($dispatcher);

(new Configurator($injector))->createServer([[
    'listenOn'    => '*:1337',
    'application' => 'ExampleAsyncApp'
]])->start();

