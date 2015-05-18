<?php

use Aerys\{ Host, Router, Request, Response };

/* --- Global server options -------------------------------------------------------------------- */

const AERYS_OPTIONS = [
    "keepAliveTimeout" => 60,
    "deflateMinimumLength" => 0,
];

/* --- http://localhost:1337/ ------------------------------------------------------------------- */

$router = (new Router)
    ->get("/", function(Request $req, Response $res) {
        $res->send("<html><body><h1>Hello, world.</h1></body></html>");
    })
    ->post("/", function(Request $req, Response $res) {
        $res->send("<html><body><h1>Hello, world (POST).</h1></body></html>");
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
        $res->send("<html><body><h1>Dual directory match</h1></body></html>");
    })
    ->get("/long-poll", function(Request $req, Response $res) {
        while (true) {
            $res->stream("hello!<br/>")->flush();
            yield new Amp\Pause(1000);
        }
    })
    ->post("/body1", function(Request $req, Response $res) {
        $body = yield $req->body->buffer();
        $res->send("<html><body><h1>Buffer Body Echo:</h1><pre>{$body}</pre></body></html>");
    })
;

$fallback = function(Request $req, Response $res) {
    $res->send("<html><body><h1>Fallback \o/</h1></body></html>");
};

(new Host)->add($router)->add($fallback);
