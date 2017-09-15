<?php

// Ignore this if-statement, it serves only to prevent running this file directly.
if (!class_exists(Aerys\Process::class, false)) {
    echo "This file is not supposed to be invoked directly. To run it, use `php bin/aerys -c demo.php`.\n";
    exit(1);
}

use Aerys\{ Host, Request, Response, Websocket, function root, function router, function websocket };

/* --- Global server options -------------------------------------------------------------------- */

const AERYS_OPTIONS = [
    "connectionTimeout" => 60,
    //"deflateMinimumLength" => 0,
];

/* --- http://localhost:1337/ ------------------------------------------------------------------- */

$router = router()
    ->route("GET", "/", function(Request $req, Response $res) {
        $res->end("<html><body><h1>Hello, world.</h1></body></html>");
    })
    ->route("GET", "/router/{myarg}", function(Request $req, Response $res, array $routeArgs) {
        $body = "<html><body><h1>Route Args at param 3</h1>".print_r($routeArgs, true)."</body></html>";
        $res->end($body);
    })
    ->route("POST", "/", function(Request $req, Response $res) {
        $res->end("<html><body><h1>Hello, world (POST).</h1></body></html>");
    })
    ->route("GET", "error1", function(Request $req, Response $res) {
        // ^ the router normalizes the leading forward slash in your URIs
        $nonexistent->methodCall();
    })
    ->route("GET", "/error2", function(Request $req, Response $res) {
        throw new Exception("wooooooooo!");
    })
    ->route("GET", "/directory/?", function(Request $req, Response $res) {
        // The trailing "/?" in the URI allows this route to match /directory OR /directory/
        $res->end("<html><body><h1>Dual directory match</h1></body></html>");
    })
    ->route("GET", "/long-poll", function(Request $req, Response $res) {
        while (true) {
            $res->write("hello!<br/>");
            $res->flush();
            yield new Amp\Delayed(1000);
        }
    })
    ->route("POST", "/body1", function(Request $req, Response $res) {
        $body = yield $req->getBody();
        $res->end("<html><body><h1>Buffer Body Echo:</h1><pre>{$body}</pre></body></html>");
    })
    ->route("POST", "/body2", function(Request $req, Response $res) {
        $body = "";
        while (null != $chunk = yield $req->getBody()->read()) {
            $body .= $chunk;
        }
        $res->end("<html><body><h1>Stream Body Echo:</h1><pre>{$body}</pre></body></html>");
    })
    ->route("GET", "/favicon.ico", function(Request $req, Response $res) {
        $res->setStatus(404);
        $res->end(Aerys\makeGenericBody(404));
    })
    ->route("ZANZIBAR", "/zanzibar", function (Request $req, Response $res) {
        $res->end("<html><body><h1>ZANZIBAR!</h1></body></html>");
    });

$websocket = websocket(new class implements Websocket {
    private $endpoint;

    public function onStart(Websocket\Endpoint $endpoint) {
        $this->endpoint = $endpoint;
    }

    public function onHandshake(Request $request, Response $response) { /* check origin header here */ }
    public function onOpen(int $clientId, $handshakeData) { }

    public function onData(int $clientId, Websocket\Message $msg) {
        // broadcast to all connected clients
        $this->endpoint->broadcast(yield $msg);
    }

    public function onClose(int $clientId, int $code, string $reason) { }
    public function onStop() { }
});

$router->route("GET", "/ws", $websocket);

// If none of our routes match try to serve a static file
$root = root($docrootPath = __DIR__);

// If no static files match fallback to this
$fallback = function(Request $req, Response $res) {
    $res->end("<html><body><h1>Fallback \o/</h1></body></html>");
};

return (new Host)->expose("*", 1337)->use($router)->use($root)->use($fallback);
