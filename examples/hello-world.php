#!/usr/bin/env php
<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Http\Server\HttpSocketServer;
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
        ->withTlsContext((new Socket\ServerTlsContext)
                ->withDefaultCertificate($cert));

$logHandler = new StreamHandler(ByteStream\getStdout());
$logHandler->pushProcessor(new PsrLogMessageProcessor());
$logHandler->setFormatter(new ConsoleFormatter);
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = new HttpSocketServer($logger);

$server->expose(new Socket\InternetAddress("0.0.0.0", 1337));
$server->expose(new Socket\InternetAddress("[::]", 1337));
$server->expose(new Socket\InternetAddress("0.0.0.0", 1338), $context);
$server->expose(new Socket\InternetAddress("[::]", 1338), $context);

$server->start(new ClosureRequestHandler(static function (Request $request): Response {
    return new Response(Status::OK, [
            "content-type" => "text/plain; charset=utf-8",
    ], "Hello, World!");
}));

// Await SIGINT or SIGTERM to be received.
$signal = trapSignal([\SIGINT, \SIGTERM]);

$logger->info(sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();
