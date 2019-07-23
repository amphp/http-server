#!/usr/bin/env php
<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream\ResourceOutputStream;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;

// Run this script, then visit http://localhost:1337/ in your browser.

Amp\Loop::run(static function () {
    $cert = new Socket\Certificate(__DIR__ . '/../test/server.pem');

    $servers = [
        Socket\listen("0.0.0.0:1337"),
        Socket\listen("[::]:1337"),
        Socket\listen("0.0.0.0:1338", null, (new Socket\ServerTlsContext)->withDefaultCertificate($cert)),
        Socket\listen("[::]:1338", null, (new Socket\ServerTlsContext)->withDefaultCertificate($cert)),
    ];

    $logHandler = new StreamHandler(new ResourceOutputStream(STDOUT));
    $logHandler->setFormatter(new ConsoleFormatter);
    $logger = new Logger('server');
    $logger->pushHandler($logHandler);

    $server = new Server($servers, new CallableRequestHandler(static function () {
        return new Response(Status::OK, [
            "content-type" => "text/plain; charset=utf-8"
        ], "Hello, World!");
    }), $logger);

    yield $server->start();

    // Stop the server when SIGINT is received (this is technically optional, but it is best to call Server::stop()).
    Amp\Loop::onSignal(SIGINT, static function (string $watcherId) use ($server) {
        Amp\Loop::cancel($watcherId);
        yield $server->stop();
    });
});
