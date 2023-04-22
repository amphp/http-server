#!/usr/bin/env php
<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream\WritableResourceStream;
use Amp\CancelledException;
use Amp\Http\HttpStatus;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Amp\TimeoutCancellation;
use Monolog\Level;
use Monolog\Logger;
use function Amp\trapSignal;

// Used for testing against h2spec (https://github.com/summerwind/h2spec)

$cert = new Socket\Certificate(__DIR__ . '/server.pem');

$context = (new Socket\BindContext)
        ->withTlsContext((new Socket\ServerTlsContext)->withDefaultCertificate($cert));

$logHandler = new StreamHandler(new WritableResourceStream(STDOUT));
$logHandler->setFormatter(new ConsoleFormatter);
$logHandler->setLevel(Level::Info);
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = SocketHttpServer::createForDirectAccess($logger);

$server->expose(new Socket\InternetAddress("0.0.0.0", 1338), $context);
$server->expose(new Socket\InternetAddress("[::]", 1338), $context);

$server->start(new ClosureRequestHandler(static function (Request $request) {
    try {
        // Buffer entire body, but timeout after 100ms.
        $body = $request->getBody()->buffer(new TimeoutCancellation(0.1));
    } catch (ClientException) {
        // Ignore failure to read body due to RST_STREAM frames.
    } catch (CancelledException) {
        // Ignore failure to read body tue to timeout.
    }

    return new Response(HttpStatus::OK, [
        "content-type" => "text/plain; charset=utf-8",
    ], "Hello, World!");
}), new DefaultErrorHandler());

trapSignal(\SIGINT);

$server->stop();
