#!/usr/bin/env php
<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Http\Server\HttpSocketServer;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use function Amp\trapSignal;

// Run this script, then visit http://localhost:1337/ in your browser.

$servers = [
    Socket\listen("0.0.0.0:1337"),
    Socket\listen("[::]:1337"),
];

$logHandler = new StreamHandler(ByteStream\getStdout());
$logHandler->pushProcessor(new PsrLogMessageProcessor());
$logHandler->setFormatter(new ConsoleFormatter);
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = new HttpSocketServer($servers, $logger);

$server->start(new ClosureRequestHandler(function () {
    throw new \Exception("Something went wrong :-(");
}));

// Await SIGINT or SIGTERM to be received.
$signal = trapSignal([\SIGINT, \SIGTERM]);

$logger->info(sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();
