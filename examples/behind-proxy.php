#!/usr/bin/env php
<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Middleware\Forwarded;
use Amp\Http\Server\Middleware\ForwardedHeaderType;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use function Amp\trapSignal;

// Example configuration for a server to be run behind a proxy such as nginx.

$logHandler = new StreamHandler(ByteStream\getStdout());
$logHandler->pushProcessor(new PsrLogMessageProcessor());
$logHandler->setFormatter(new ConsoleFormatter());
$logger = new Logger('server');
$logger->pushHandler($logHandler);
$logger->useLoggingLoopDetection(false);

$server = SocketHttpServer::createForBehindProxy(
    logger: $logger,
    headerType: ForwardedHeaderType::XForwardedFor,
    trustedProxies: ["172.18.0.0/24"],
);

$server->expose("0.0.0.0:8080");

$server->start(new class implements RequestHandler {
    public function handleRequest(Request $request): Response
    {
        /** @var Forwarded|null $forwarded */
        $forwarded = $request->getAttribute(Forwarded::class);
        $for = $forwarded?->getFor() ?? $request->getClient()->getRemoteAddress();

        return new Response(
            status: HttpStatus::OK,
            headers: ["content-type" => "text/plain; charset=utf-8"],
            body: "Hello, " . $for->toString(),
        );
    }
}, new DefaultErrorHandler());

// Await a termination signal to be received.
$signal = trapSignal([\SIGHUP, \SIGINT, \SIGQUIT, \SIGTERM]);

$logger->info(sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();
