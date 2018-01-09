<?php

use Aerys\Middleware;
use Aerys\Request;
use Aerys\Responder;
use Aerys\Response;
use Aerys\Server;
use Amp\Promise;
use Amp\Success;

return function () {
    ($server = new Server)
        ->expose("*", 80)
        ->use(new class implements Middleware {
            public function process(Request $request, Responder $responder): Promise {
                $request->setAttribute("responder", $request->getAttribute("responder") + 1);
                return $responder->respond($request);
            }
        })
        ->use(function (Request $req) {
            $req->setAttribute("foo.bar", $req->getAttribute("foo.bar") + 1);
            return new Response\EmptyResponse;
        });

    return new Success($server);
};
