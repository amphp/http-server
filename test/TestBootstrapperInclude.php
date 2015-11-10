<?php

const AERYS_OPTIONS = [
    "shutdownTimeout" => 5000
];

class OurMiddleware implements \Aerys\Middleware {
    public function do(\Aerys\InternalRequest $ireq) {
        // We have a middleware
    }

    public function __invoke(\Aerys\Request $req, \Aerys\Response $res) {
        // and a responder
    }
}

new Aerys\Host;

$host = (new Aerys\Host)->expose("127.0.0.1", 80)->name("example.com")->use(new class implements \Aerys\Bootable {
    function boot(\Aerys\Server $server, \Aerys\Logger $logger) {
        return new OurMiddleware;
    }
});
(clone $host)->name("foo.bar")->use(function(\Aerys\Request $req, \Aerys\Response $res) { });

return new Amp\Success;