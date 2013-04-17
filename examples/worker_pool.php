<?php

/**
 * examples/worker_pool.php
 * 
 * Callback-style programming sucks. And if your application generates a fatal error in an event
 * loop it can bring down the entire server. Aerys provides a solution to this problem in the form
 * of a built-in worker pool application handler.
 * 
 * Additionally, this functionality means that as long as the worker process on the other end adheres
 * to the AMP messaging protocol and provides an adapter for the ASGI spec it can respond to Aerys
 * requests. As a result, it's possible to use Aerys in front of web applications written in any
 * programming language.
 * 
 * To start this example server:
 * 
 * $ php worker_pool.php
 * 
 * Once the server has started, request http://127.0.0.1:1337/ in your browser or client of choice.
 * The example handler specifed below ($handler) will *stream* a "Hello World" response to all client
 * requests using an Iterator body.
 * 
 * There are specific requirements for the handler file specified for use in a worker pool app. These
 * are covered in the example handler file used below, "support_files/worker_pool_app.php" ...
 */

use Aerys\Config\Configurator,
    Aerys\Config\WorkerPoolApp;

require dirname(__DIR__) . '/autoload.php';

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

$phpBin  = '/usr/bin/php';                                 // Or e.g. "C:/php/php.exe" on windows
$worker  = dirname(__DIR__) . '/workers/php/worker.php';   // The built-in PHP worker script
$handler = __DIR__ . '/support_files/worker_pool_app.php'; // <-- MUST specify `main()` app callable

$workerCmd = $phpBin . ' ' . $worker . ' ' . $handler;

(new Configurator)->createServer([[
    'listenOn'      => '127.0.0.1:1337',
    'application'   => new WorkerPoolApp([
        'workerCmd'         => $workerCmd,
        'poolSize'          => 8,
        'responseTimeout'   => 5,
        'workerCwd'         => NULL
    ])
]])->listen();

