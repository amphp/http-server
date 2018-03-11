<?php

use Amp\Http\Server\CallableResponder;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Success;

return function () {
    $responder = new CallableResponder(function () {
        return new Response\EmptyResponse;
    });

    $server = new Server($responder);
    $server->expose("*", 80);

    return new Success($server);
};
