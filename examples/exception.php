#!/usr/bin/env php
<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream\ResourceOutputStream;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;

// Run this script, then visit http://localhost:1337/ in your browser.

$servers = [
    Socket\Server::listen("0.0.0.0:1337"),
    Socket\Server::listen("[::]:1337"),
];

$logHandler = new StreamHandler(new ResourceOutputStream(\STDOUT));
$logHandler->setFormatter(new ConsoleFormatter);
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = new HttpServer($servers, new CallableRequestHandler(function () {
    throw new \Exception("Something went wrong :-(");
}), $logger);

$server->start();

// Await SIGINT, SIGTERM, or SIGSTOP to be received.
$signal = Amp\signal(\SIGINT, \SIGTERM, \SIGSTOP);

$logger->info(\sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();
