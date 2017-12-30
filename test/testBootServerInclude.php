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

class OurMiddleware implements Middleware {
    public function process(Request $request, Responder $responder): Promise {
        return $responder->respond($request);
    }

    public function __invoke(\Aerys\Request $req) {
        $req->setAttribute("responder", $req->getAttribute("responder") + 1);
    }
}

return (function () {
    yield new Success('test boot config');

    ($hosts[] = new Host)
        ->name("localhost")
        ->encrypt(__DIR__."/server.pem");

    ($hosts[] = new Host)
        ->expose("127.0.0.1", 80)
        ->name("example.com")
        ->use(new class implements \Aerys\Bootable {
            public function boot(\Aerys\Server $server, \Psr\Log\LoggerInterface $logger) {
                return new OurMiddleware;
            }
        });

    ($hosts[] = clone end($hosts))
        ->name("foo.bar")
        ->use(function (Request $req) {
            $req->setAttribute("foo.bar", $req->getAttribute("foo.bar") + 1);
            return new Response\EmptyResponse;
        });

    return new Success($hosts);
})();
