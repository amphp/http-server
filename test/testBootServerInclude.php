<?php

use Aerys\CallableResponder;
use Aerys\Response;
use Aerys\Server;
use Amp\Success;

return function () {
    $responder = new CallableResponder(function () {
        return new Response\EmptyResponse;
    });

    $server = new Server($responder);
    $server->expose("*", 80);

    return new Success($server);
};
