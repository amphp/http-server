<?php

/**
 * examples/apm_process_manager.php
 * 
 * Callback-style programming sucks. And if your application generates a fatal error in an event
 * loop it can bring down the entire server. Aerys provides a solution to this problem in the form
 * of a built-in process manager. The process manager generates a pool of worker processes when
 * the server is started and subsequently distributes client requests to the workers for handling.
 * 
 * The process manager also makes it possible to use Aerys in front of applications written in
 * languages that AREN'T PHP. As long as the application on the other end adheres to the APM 
 * messaging protocol it can respond to requests distributed by process manager.
 * 
 * Aerys comes packaged with a worker process file to communicate with PHP application handlers.
 * Application handlers using this worker file MUST specify a `main(array $asgiEnv)` function  
 * to respond to client requests. The only difference between application handlers using the 
 * process manager and those that hook into the standard event loop is that process manager handlers
 * CANNOT return stream resources or Iterator instances as the entity body parameter; these body
 * types cannot be passed across the pipe to the process manager. As a result the process manager,
 * just like normal PHP web SAPIs, is not ideal for streaming. However, if static file streaming is
 * needed applications can simply enable mod.sendfile for high-performance file serving in tandem
 * with process manager application handling.
 * 
 * To start this example server:
 * 
 * $ php apm_process_manager.php
 * 
 * Once the server has started, request http://127.0.0.1:1337/ in your browser or client of choice.
 * The example handler specifed below ($handler) will return a basic HTML "Hello World" response
 * to all client requests.
 */

require dirname(__DIR__) . '/autoload.php';

date_default_timezone_set('GMT');

$phpBin  = '/usr/bin/php'; // Or something like "C:\\php\\php.exe" on windows
$worker  = dirname(__DIR__) . '/apm_worker.php'; // The worker script
$handler = __DIR__ . '/apm_example_handler.php'; // MUST specify a main() function to return your app

$cmd = $phpBin . ' ' . $worker . ' ' . $handler;

(new Aerys\ServerFactory)->createServer([[
    'listen' => '*:1337',
    'handler' => new Aerys\Apm\ProcessManager($cmd)
]])->listen();

