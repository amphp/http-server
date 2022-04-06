#!/usr/bin/env php
<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use function Amp\delay;
use function Amp\trapSignal;

// Run this script, then visit http://localhost:1337/ in your browser.

$logHandler = new StreamHandler(ByteStream\getStdout());
$logHandler->pushProcessor(new PsrLogMessageProcessor());
$logHandler->setFormatter(new ConsoleFormatter());
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = new SocketHttpServer($logger);

$server->expose(new Socket\InternetAddress("0.0.0.0", 1337));
$server->expose(new Socket\InternetAddress("[::]", 1337));

$server->start(new ClosureRequestHandler(function (Request $request): Response {
    // We delay the response here, but this could also be non-blocking I/O.
    // Further requests are still processed concurrently.
    delay(3);

    return new Response(Status::OK, [
        "content-type" => "text/plain; charset=utf-8",
    ], "Hello, World!");
}), new DefaultErrorHandler());

// Await SIGINT or SIGTERM to be received.
$signal = trapSignal([\SIGINT, \SIGTERM]);

$logger->info(sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();
