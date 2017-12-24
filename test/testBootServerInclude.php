<?php

const AERYS_OPTIONS = [
    "shutdownTimeout" => 5000
];

class OurFilter implements \Aerys\Filter {
    public function filter(\Aerys\Internal\Request $ireq) {
        // We have a filter
    }

    public function __invoke(\Aerys\Request $req) {
        $req->setAttribute("responder", $req->getAttribute("responder") + 1);
    }
}

return (function () {
    yield new Amp\Success('test boot config');

    ($hosts[] = new Aerys\Host)
        ->name("localhost")
        ->encrypt(__DIR__."/server.pem");

    ($hosts[] = new Aerys\Host)
        ->expose("127.0.0.1", 80)
        ->name("example.com")
        ->use(new class implements \Aerys\Bootable {
            public function boot(\Aerys\Server $server, \Psr\Log\LoggerInterface $logger) {
                return new OurFilter;
            }
        });

    ($hosts[] = clone end($hosts))
        ->name("foo.bar")
        ->use(function (\Aerys\Request $req, \Aerys\Response $res) {
            $req->setAttribute("foo.bar", $req->getAttribute("foo.bar") + 1);
            $res->end();
        });

    return new Amp\Success($hosts);
})();
