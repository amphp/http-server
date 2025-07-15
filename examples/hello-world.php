#!/usr/bin/env php
<?php declare(strict_types=1);

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Amp\Socket\BindContext;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use function Amp\trapSignal;

// Run this script, then visit http://localhost:1337/ or https://localhost:1338/ in your browser.

$cert = new Socket\Certificate(__DIR__ . '/../test/server.pem');

// BindContext::withTcpNoDelay() returns a context which disables Nagle's algorithm, decreasing
// latency when responses are very small (as is the case here). This generally is not necessary
// for a production server but can negatively affect benchmarks.
$plainBindContext = (new BindContext)->withTcpNoDelay();

$tlsBindContext = $plainBindContext
        ->withTlsContext((new Socket\ServerTlsContext)
                ->withDefaultCertificate($cert));

$logHandler = new StreamHandler(ByteStream\getStdout());
$logHandler->pushProcessor(new PsrLogMessageProcessor());
$logHandler->setFormatter(new ConsoleFormatter());
$logger = new Logger('server');
$logger->pushHandler($logHandler);
$logger->useLoggingLoopDetection(false);

$server = new SocketHttpServer(
    $logger,
    new Socket\ResourceServerSocketFactory(),
    new SocketClientFactory($logger),
);

$server->expose("0.0.0.0:1337", $plainBindContext);
$server->expose("[::]:1337", $plainBindContext);
$server->expose("0.0.0.0:1338", $tlsBindContext);
$server->expose("[::]:1338", $tlsBindContext);

$server->start(new class implements RequestHandler {
    public function handleRequest(Request $request): Response
    {
        return new Response(
            status: HttpStatus::OK,
            headers: ["content-type" => "text/plain; charset=utf-8"],
            body: "Hello, World!",
        );
    }
}, new DefaultErrorHandler());

// Await a termination signal to be received.
$signal = trapSignal([\SIGHUP, \SIGINT, \SIGQUIT, \SIGTERM]);

$logger->info(sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();
