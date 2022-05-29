#!/usr/bin/env php
<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use function Amp\delay;
use function Amp\trapSignal;

// Run this script, then visit http://localhost:1337/ in your browser.

$html = <<<HTML
<html lang="en">
<head>
    <title>Event Source Demo</title>
</head>
<body>
    <script>
        const eventSource = new EventSource('/events');
        const eventList = document.createElement('ol');
        document.body.appendChild(eventList);
        eventSource.addEventListener('notification', function (e) {
            const element = document.createElement('li');
            element.textContent = 'Message: ' + e.data;
            eventList.appendChild(element);
        });
    </script>
</body>
</html>
HTML;

$logHandler = new StreamHandler(ByteStream\getStdout());
$logHandler->pushProcessor(new PsrLogMessageProcessor());
$logHandler->setFormatter(new ConsoleFormatter);
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = new SocketHttpServer($logger, enableCompression: true);

$server->expose(new Socket\InternetAddress("0.0.0.0", 1337));
$server->expose(new Socket\InternetAddress("[::]", 1337));

$server->start(new ClosureRequestHandler(function (Request $request) use ($html): Response {
    $path = $request->getUri()->getPath();

    if ($path === '/') {
        return new Response(
            status: Status::OK,
            headers: ["content-type" => "text/html; charset=utf-8"],
            body: $html,
        );
    }

    if ($path === '/events') {
        // We stream the response here, one event every 500 ms.
        return new Response(
            status: Status::OK,
            headers: ["content-type" => "text/event-stream; charset=utf-8"],
            body: new ReadableIterableStream((function () {
                for ($i = 0; $i < 30; $i++) {
                    delay(0.5);
                    yield "event: notification\ndata: Event {$i}\n\n";
                }
            })()),
        );
    }

    return new Response(Status::NOT_FOUND);
}), new DefaultErrorHandler());

// Await SIGINT or SIGTERM to be received.
$signal = trapSignal([\SIGINT, \SIGTERM]);

$logger->info(sprintf("Received signal %d, stopping HTTP server", $signal));

$server->stop();
