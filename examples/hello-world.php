#!/usr/bin/env php
<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Http\Server\CallableResponder;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use League\Uri\Components\Query;

// Run this script, then visit http://localhost:8080/?name=Your-name in your browser.

Amp\Loop::run(function () {
    $responder = new CallableResponder(function (Request $request) {
        $query = new Query($request->getUri()->getQuery());
        $name = $query->getParam('name');
        return new Response\TextResponse("Hello, " . ($name ?? "world") . "!");
    });

    $server = new Server($responder);
    $server->expose("*", 8080);

    // Stop the server when SIGINT is received (this is technically optional, but it is best to call Server::stop()).
    Amp\Loop::onSignal(SIGINT, function (string $watcherId) use ($server) {
        Amp\Loop::cancel($watcherId); // Cancel this watcher to automatically stop the loop.
        $server->stop();
    });

    $server->start();
});
