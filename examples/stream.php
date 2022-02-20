#!/usr/bin/env php
<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;
use function Amp\delay;
use function Amp\trapSignal;

// Run this script, then visit http://localhost:1337/ in your browser.

$servers = [
    Socket\listen("0.0.0.0:1337"),
    Socket\listen("[::]:1337"),
];

$logHandler = new StreamHandler(ByteStream\getStdout());
$logHandler->setFormatter(new ConsoleFormatter);
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = new HttpServer($servers, new ClosureRequestHandler(function (Request $request): Response {
    // We stream the response here, one line every 100 ms.
    return new Response(Status::OK, [
        "content-type" => "text/plain; charset=utf-8",
    ], new ReadableIterableStream((function () {
        for ($i = 0; $i < 30; $i++) {
            delay(0.1);
            yield "Line {$i}\r\n";
        }
    })()));
}), $logger, (new Options)->withoutCompression());

$server->start();

// Await SIGINT or SIGTERM to be received.
$signal = trapSignal([\SIGINT, \SIGTERM]);

$logger->info(sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();
