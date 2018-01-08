<?php

use Aerys\Host;
use Aerys\Middleware;
use Aerys\Request;
use Aerys\Responder;
use Aerys\Response;
use Amp\Promise;
use Amp\Success;

const AERYS_OPTIONS = [
    "shutdownTimeout" => 5000
];

return (function () {
    ($host = new Host)
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

    return new Success($host);
})();
