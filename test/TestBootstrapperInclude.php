<?php

const AERYS_OPTIONS = [
    "shutdownTimeout" => 5000
];

class OurMiddleware implements \Aerys\Middleware {
    public function do(\Aerys\InternalRequest $ireq) {
        // We have a middleware
    }

    public function __invoke(\Aerys\Request $req, \Aerys\Response $res) {
        $req->setLocalVar("responder", $req->getLocalVar("responder") + 1);
    }
}

(new Aerys\Host)->encrypt(__DIR__."/server.pem");

$host = (new Aerys\Host)->expose("127.0.0.1", 80)->name("example.com")->use(new class implements \Aerys\Bootable {
    function boot(\Aerys\Server $server, \Psr\Log\LoggerInterface $logger) {
        return new OurMiddleware;
    }
});
(clone $host)->name("foo.bar")->use(function(\Aerys\Request $req, \Aerys\Response $res) { $req->setLocalVar("foo.bar", $req->getLocalVar("foo.bar") + 1); $res->end(); });

return function() { return new Amp\Success; };