#!/usr/bin/env php
<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use function Amp\trapSignal;

// Run this script, then visit http://localhost:1337/ or https://localhost:1338/ in your browser.

$cert = new Socket\Certificate(__DIR__ . '/../test/server.pem');

$context = (new Socket\BindContext)
        ->withTlsContext((new Socket\ServerTlsContext)->withDefaultCertificate($cert));

$servers = [
        Socket\listen("0.0.0.0:1337"),
        Socket\listen("[::]:1337"),
        Socket\listen("0.0.0.0:1338", $context),
        Socket\listen("[::]:1338", $context),
];

$logHandler = new StreamHandler(ByteStream\getStdout());
$logHandler->pushProcessor(new PsrLogMessageProcessor());
$logHandler->setFormatter(new ConsoleFormatter);
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = new HttpServer($servers, new ClosureRequestHandler(static function (Request $request): Response {
    return new Response(Status::OK, [
            "content-type" => "text/plain; charset=utf-8",
    ], "Hello, World!");
}), $logger);

$server->start();

// Await SIGINT or SIGTERM to be received.
$signal = trapSignal([\SIGINT, \SIGTERM]);

$logger->info(sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();
