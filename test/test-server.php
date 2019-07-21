#!/usr/bin/env php
<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream\ResourceOutputStream;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;

// Used for testing against h2spec (https://github.com/summerwind/h2spec)

Amp\Loop::run(static function () {
    $cert = new Socket\Certificate(__DIR__ . '/server.pem');

    $servers = [
        Socket\listen("0.0.0.0:1338", null, (new Socket\ServerTlsContext)->withDefaultCertificate($cert)),
        Socket\listen("[::]:1338", null, (new Socket\ServerTlsContext)->withDefaultCertificate($cert)),
    ];

    $logHandler = new StreamHandler(new ResourceOutputStream(STDOUT));
    $logHandler->setFormatter(new ConsoleFormatter);
    $logger = new Logger('server');
    $logger->pushHandler($logHandler);

    $server = new Server($servers, new CallableRequestHandler(static function (Request $request) {
        try {
            $body = yield $request->getBody()->buffer(); // Buffer entire request body into memory (unused here).
        } catch (ClientException $exception) {
            // Ignore failure to read body due to RST_STREAM frames.
        }

        return new Response(Status::OK, [
            "content-type" => "text/plain; charset=utf-8"
        ], "Hello, World!");
    }), $logger);

    yield $server->start();

    Amp\Loop::onSignal(SIGINT, static function (string $watcherId) use ($server) {
        Amp\Loop::cancel($watcherId);
        yield $server->stop();
    });
});
