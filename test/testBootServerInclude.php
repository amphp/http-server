<?php

use Aerys\CallableResponder;
use Aerys\Request;
use Aerys\Response;
use Aerys\Server;
use Amp\Success;

return function () {
    $responder = new CallableResponder(function (Request $req) {
        $req->setAttribute("foo.bar", $req->getAttribute("foo.bar") + 1);
        return new Response\EmptyResponse;
    });

    $server = (new Server($responder))->expose("*", 80);

    return new Success($server);
};
