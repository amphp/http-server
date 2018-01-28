<?php

// Ignore this if-statement, it serves only to prevent running this file directly.
if (!class_exists(Aerys\Process::class, false)) {
    echo "This file is not supposed to be invoked directly. To run it, use `php bin/aerys -c demo.php`.\n";
    exit(1);
}

use Aerys\CallableResponder;
use Aerys\Request;
use Aerys\Response;
use Aerys\Root;
use Aerys\RouteArguments;
use Aerys\Router;
use Aerys\Server;
use Aerys\Websocket\Application;
use Aerys\Websocket\Endpoint;
use Aerys\Websocket\Message;
use Aerys\Websocket\Websocket;
use Amp\ByteStream\InputStream;
use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\StreamException;
use Amp\Failure;
use Amp\Promise;

// Return a function that defines and returns a Server instance.
return function (Aerys\Options $options, Aerys\Logger $logger, Aerys\Console $console): Server {

    /* --- Server options --------------------------------------------------------------------------- */

    $options = $options
        ->withConnectionTimeout(60) // Allows up to 60s for request receive and keep-alive timeout
        ->withAllowedMethods(["GET", "HEAD", "POST", "ZANZIBAR"]); // Limit allowed methods (with custom)

    /* --- http://localhost:1337/ ------------------------------------------------------------------- */

    $router = new Router;

    $router->addRoute("GET", "/", new CallableResponder(function (): Response {
        return new Response\HtmlResponse("<html><body><h1>Hello, world.</h1></body></html>");
    }));

    $router->addRoute("GET", "/router/{myarg}", new CallableResponder(function (Request $request): Response {
        $routeArgs = $request->get(RouteArguments::class);
        $body = "<html><body><h1>Route Args</h1><p>myarg =&gt; " . \htmlspecialchars($routeArgs->get('myarg')) . "</p></body></html>";
        return new Response\HtmlResponse($body);
    }));

    $router->addRoute("POST", "/", new CallableResponder(function (Request $request): Response {
        return new Response\HtmlResponse("<html><body><h1>Hello, world (POST).</h1></body></html>");
    }));

    $router->addRoute("GET", "error1", new CallableResponder(function (Request $request): Response {
        // ^ the router normalizes the leading forward slash in your URIs
        $nonexistent->methodCall();
    }));

    $router->addRoute("GET", "/error2", new CallableResponder(function (Request $request): Response {
        throw new Exception("wooooooooo!");
    }));

    $router->addRoute("GET", "/directory/?", new CallableResponder(function (Request $request) {
        // The trailing "/?" in the URI allows this route to match /directory OR /directory/
        return new Response\HtmlResponse("<html><body><h1>Dual directory match</h1></body></html>");
    }));

    $router->addRoute("POST", "/body1", new CallableResponder(function (Request $request): \Generator {
        $body = yield $request->getBody()->buffer();
        return new Response\HtmlResponse("<html><body><h1>Buffer Body Echo:</h1><pre>{$body}</pre></body></html>");
    }));

    $router->addRoute("POST", "/body2", new CallableResponder(function (Request $request): \Generator {
        $body = "";
        while (null !== $chunk = yield $request->getBody()->read()) {
            $body .= $chunk;
        }
        return new Response\HtmlResponse("<html><body><h1>Stream Body Echo:</h1><pre>{$body}</pre></body></html>");
    }));

    $router->addRoute("GET", "/body-stream-error", new CallableResponder(function (Request $request): Response {
        $body = new class implements InputStream {
            public function read(): Promise {
                return new Failure(new StreamException("Something went wrong..."));
            }
        };

        return new Response($body);
    }));

    $router->addRoute("ZANZIBAR", "/zanzibar", new CallableResponder(function (Request $request): Response {
        return new Response\HtmlResponse("<html><body><h1>ZANZIBAR!</h1></body></html>");
    }));

    $websocket = new Websocket(new class implements Application {
        /** @var Endpoint */
        private $endpoint;

        public function onStart(Endpoint $endpoint) {
            // Called once when the server is starting.
            $this->endpoint = $endpoint;
        }

        public function onHandshake(Request $request, Response $response) {
            // Check origin header here, return a new response to deny the connection.
            return $response;
        }

        public function onOpen(int $clientId, Request $request) {
            // Called when a client connection is accepted.
        }

        public function onData(int $clientId, Message $message) {
            // Broadcast text messages to all connected clients
            if (!$message->isBinary()) {
                $this->endpoint->broadcast(yield $message->buffer());
            }
        }

        public function onClose(int $clientId, int $code, string $reason) {
            // Called when a client connection is closed (by the server or client).
        }

        public function onStop() {
            // Called once when the server is stopping.
        }
    });

    $router->addRoute("GET", "/ws", $websocket);

    // If none of our routes match try to serve a static file
    $router->setFallback(new Root($docrootPath = __DIR__));

    $server = new Server($router, $options, $logger);
    $server->expose("*", 1337);

    return $server;
};
