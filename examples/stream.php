#!/usr/bin/env php
<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\AsyncGenerator;
use Amp\ByteStream\PipelineStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;
use function Amp\delay;

// Run this script, then visit http://localhost:1337/ in your browser.

$servers = [
    Socket\Server::listen("0.0.0.0:1337"),
    Socket\Server::listen("[::]:1337"),
];

$logHandler = new StreamHandler(new ResourceOutputStream(\STDOUT));
$logHandler->setFormatter(new ConsoleFormatter);
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = new HttpServer($servers, new CallableRequestHandler(function (Request $request): Response {
    // We stream the response here, one line every 100 ms.
    return new Response(Status::OK, [
        "content-type" => "text/plain; charset=utf-8",
    ], new PipelineStream(new AsyncGenerator(function () {
        for ($i = 0; $i < 30; $i++) {
            delay(100);
            yield "Line {$i}\r\n";
        }
    })));
}), $logger, (new Options)->withoutCompression());

$server->start();

// Await SIGINT, SIGTERM, or SIGSTOP to be received.
$signal = Amp\signal(\SIGINT, \SIGTERM, \SIGSTOP);

$logger->info(\sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();
