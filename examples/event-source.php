#!/usr/bin/env php
<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream\IteratorStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Delayed;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Producer;
use Amp\Socket;
use Monolog\Logger;

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

Amp\Loop::run(function () use ($html) {
    $servers = [
        Socket\Server::listen("0.0.0.0:1337"),
        Socket\Server::listen("[::]:1337"),
    ];

    $logHandler = new StreamHandler(new ResourceOutputStream(\STDOUT));
    $logHandler->setFormatter(new ConsoleFormatter);
    $logger = new Logger('server');
    $logger->pushHandler($logHandler);

    $server = new HttpServer($servers, new CallableRequestHandler(function (Request $request) use ($html) {
        $path = $request->getUri()->getPath();

        if ($path === '/') {
            return new Response(Status::OK, [
                "content-type" => "text/html; charset=utf-8",
            ], $html);
        }

        if ($path === '/events') {
            // We stream the response here, one event every 500 ms.
            return new Response(Status::OK, [
                    "content-type" => "text/event-stream; charset=utf-8",
            ], new IteratorStream(new Producer(function (callable $emit) {
                for ($i = 0; $i < 30; $i++) {
                    yield new Delayed(500);
                    yield $emit("event: notification\ndata: Event {$i}\n\n");
                }
            })));
        }

        return new Response(Status::NOT_FOUND);
    }), $logger);

    yield $server->start();

    // Stop the server when SIGINT is received (this is technically optional, but it is best to call Server::stop()).
    Amp\Loop::onSignal(SIGINT, function (string $watcherId) use ($server) {
        Amp\Loop::cancel($watcherId);
        yield $server->stop();
    });
});
