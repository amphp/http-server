<?php

// Ignore this if-statement, it serves only to prevent running this file directly.
if (!class_exists(Aerys\Process::class, false)) {
    echo "This file is not supposed to be invoked directly. To run it, use `php bin/aerys -c demo.php`.\n";
    exit(1);
}

use Aerys\Options;
use Aerys\Request;
use Aerys\Response;
use Aerys\Server;
use Aerys\Websocket\Endpoint;
use Aerys\Websocket\Message;
use Aerys\Websocket\Websocket;

// Return a function that defines and returns a Server instance.
return function (Aerys\Logger $logger, Aerys\Console $console): Server {

    /* --- Global server options -------------------------------------------------------------------- */

    $options = new Options;
    $options->connectionTimeout = 60;
    $options->sendServerToken = true;

    /* --- http://localhost:1337/ ------------------------------------------------------------------- */

    $router = Aerys\router()
        ->route("GET", "/", function (Request $req): Response {
            return new Response\HtmlResponse("<html><body><h1>Hello, world.</h1></body></html>");
        })
        ->route("GET", "/router/{myarg}", function (Request $req, array $routeArgs): Response {
            $body = "<html><body><h1>Route Args at param 3</h1>" . print_r($routeArgs, true) . "</body></html>";
            return new Response\HtmlResponse($body);
        })
        ->route("POST", "/", function (Request $req): Response {
            return new Response\HtmlResponse("<html><body><h1>Hello, world (POST).</h1></body></html>");
        })
        ->route("GET", "error1", function (Request $req): Response {
            // ^ the router normalizes the leading forward slash in your URIs
            $nonexistent->methodCall();
        })
        ->route("GET", "/error2", function (Request $req): Response {
            throw new Exception("wooooooooo!");
        })
        ->route("GET", "/directory/?", function (Request $req) {
            // The trailing "/?" in the URI allows this route to match /directory OR /directory/
            return new Response\HtmlResponse("<html><body><h1>Dual directory match</h1></body></html>");
        })
        ->route("POST", "/body1", function (Request $req): \Generator {
            $body = yield $req->getBody()->buffer();
            return new Response\HtmlResponse("<html><body><h1>Buffer Body Echo:</h1><pre>{$body}</pre></body></html>");
        })
        ->route("POST", "/body2", function (Request $req): \Generator {
            $body = "";
            while (null != $chunk = yield $req->getBody()->read()) {
                $body .= $chunk;
            }
            return new Response\HtmlResponse("<html><body><h1>Stream Body Echo:</h1><pre>{$body}</pre></body></html>");
        })
        ->route("GET", "/favicon.ico", function (Request $req): Response {
            $status = 404;
            $body = Aerys\makeGenericBody($status);
            return new Response\HtmlResponse($body, [], $status);
        })
        ->route("ZANZIBAR", "/zanzibar", function (Request $req): Response {
            return new Response\HtmlResponse("<html><body><h1>ZANZIBAR!</h1></body></html>");
        });

    $websocket = Aerys\websocket(new class implements Websocket {
        /** @var Endpoint */
        private $endpoint;

        public function onStart(Endpoint $endpoint) {
            $this->endpoint = $endpoint;
        }

        public function onHandshake(Request $request) { /* check origin header here */ }

        public function onOpen(int $clientId, Request $request) { }

        public function onData(int $clientId, Message $message) {
            // broadcast to all connected clients
            $this->endpoint->broadcast(yield $message->buffer());
        }

        public function onClose(int $clientId, int $code, string $reason) { }

        public function onStop() { }
    });

    $router->route("GET", "/ws", $websocket);

    // If none of our routes match try to serve a static file
    $root = Aerys\root($docrootPath = __DIR__);

    // If no static files match fallback to this
    $fallback = function (Request $req): Response {
        return new Response\HtmlResponse("<html><body><h1>Fallback \o/</h1></body></html>");
    };

    return (new Server($options, $logger))
        ->expose("*", 1337)
        ->use($router)
        ->use($root)
        ->use($fallback);
};
