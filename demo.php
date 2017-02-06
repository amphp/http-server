<?php

// <ignore>
if (!class_exists('Aerys\Process')) { if (PHP_MAJOR_VERSION < 7) { echo "To run aerys, you need to run it with PHP 7.\n"; } echo "This file is not supposed to be invoked directly. To run it, use `php bin/aerys -c demo.php`.\n"; die(1); } eval(file_get_contents(__FILE__, null, null, __COMPILER_HALT_OFFSET__)); __halt_compiler();
// </ignore>

use Aerys\{ Host, Request, Response, Router, Websocket, function root, function router, function websocket };

/* --- Global server options -------------------------------------------------------------------- */

const AERYS_OPTIONS = [
    "keepAliveTimeout" => 60,
    //"deflateMinimumLength" => 0,
];

/* --- http://localhost:1337/ ------------------------------------------------------------------- */

$router = router()
    ->get("/", function(Request $req, Response $res) {
        $res->end("<html><body><h1>Hello, world.</h1></body></html>");
    })
    ->get("/router/{myarg}", function(Request $req, Response $res, array $routeArgs) {
        $body = "<html><body><h1>Route Args at param 3</h1>".print_r($routeArgs, true)."</body></html>";
        $res->end($body);
    })
    ->post("/", function(Request $req, Response $res) {
        $res->end("<html><body><h1>Hello, world (POST).</h1></body></html>");
    })
    ->get("error1", function(Request $req, Response $res) {
        // ^ the router normalizes the leading forward slash in your URIs
        $nonexistent->methodCall();
    })
    ->get("/error2", function(Request $req, Response $res) {
        throw new Exception("wooooooooo!");
    })
    ->get("/directory/?", function(Request $req, Response $res) {
        // The trailing "/?" in the URI allows this route to match /directory OR /directory/
        $res->end("<html><body><h1>Dual directory match</h1></body></html>");
    })
    ->get("/long-poll", function(Request $req, Response $res) {
        while (true) {
            $res->stream("hello!<br/>");
            $res->flush();
            yield new Amp\Pause(1000);
        }
    })
    ->post("/body1", function(Request $req, Response $res) {
        $body = yield $req->getBody();
        $res->end("<html><body><h1>Buffer Body Echo:</h1><pre>{$body}</pre></body></html>");
    })
    ->post("/body2", function(Request $req, Response $res) {
        $body = "";
        foreach ($req->getBody()->stream() as $bodyPart) {
            $body .= yield $bodyPart;
        }
        $res->end("<html><body><h1>Stream Body Echo:</h1><pre>{$body}</pre></body></html>");
    })
    ->get("/favicon.ico", function(Request $req, Response $res) {
        $res->setStatus(404);
        $res->setHeader("Aerys-Generic-Response", "enable");
        $res->end();
    })
    ->zanzibar("/zanzibar", function (Request $req, Response $res) {
        $res->end("<html><body><h1>ZANZIBAR!</h1></body></html>");
    });

$websocket = websocket(new class implements Aerys\Websocket {
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

$router->get("/ws", $websocket);

// If none of our routes match try to serve a static file
$root = root($docrootPath = __DIR__);

// If no static files match fallback to this
$fallback = function(Request $req, Response $res) {
    $res->end("<html><body><h1>Fallback \o/</h1></body></html>");
};

(new Host)->expose("*", 1337)->use($router)->use($root)->use($fallback);
