#!/usr/bin/env php
<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream\IteratorStream;
use Amp\Delayed;
use Amp\Http\Server\CallableResponder;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Status;
use Amp\Producer;
use Amp\Socket;

// Run this script, then visit http://localhost:1337/ in your browser.

Amp\Loop::run(function () {
    $servers = [
        Socket\listen("0.0.0.0:1337"),
        Socket\listen("[::]:1337"),
    ];

    $server = new Server($servers, new CallableResponder(function (Request $request) {
        // We stream the response here, one line every 100 ms.
        return new Response(Status::OK, [
            "content-type" => "text/plain; charset=utf-8"
        ], new IteratorStream(new Producer(function (callable $emit) {
            for ($i = 0; $i < 30; $i++) {
                yield new Delayed(100);
                yield $emit("Line {$i}\r\n");
            }
        })));
    }), (new Options)->withOutputBufferSize(1));

    yield $server->start();

    // Stop the server when SIGINT is received (this is technically optional, but it is best to call Server::stop()).
    Amp\Loop::onSignal(SIGINT, function (string $watcherId) use ($server) {
        Amp\Loop::cancel($watcherId);
        yield $server->stop();
    });
});