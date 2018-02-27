<?php

use Aerys\Request;
use Aerys\Response;
use Aerys\Root;
use Aerys\Router;
use Aerys\Server;
use Aerys\Websocket\Application;
use Aerys\Websocket\Endpoint;
use Aerys\Websocket\Message;
use Amp\Artax\Client;
use Amp\Loop;

require __DIR__ . "/../../vendor/autoload.php";

$websocket = new Aerys\Websocket\Websocket(new class implements Application {
    /** @var Endpoint */
    private $endpoint;

    /** @var string|null */
    private $watcher;

    /** @var Client */
    private $http;

    /** @var int|null */
    private $newestQuestion;

    public function onStart(Endpoint $endpoint) {
        $this->endpoint = $endpoint;
        $this->http = new Amp\Artax\DefaultClient;
        $this->watcher = Loop::repeat(10000, function () {
            /** @var Response $response */
            $response = yield $this->http->request('https://api.stackexchange.com/2.2/questions?order=desc&sort=activity&site=stackoverflow');
            $json = yield $response->getBody();

            $data = \json_decode($json, true);

            foreach (\array_reverse($data["items"]) as $question) {
                if ($this->newestQuestion === null || $question["question_id"] > $this->newestQuestion) {
                    $this->newestQuestion = $question["question_id"];
                    $this->endpoint->broadcast(\json_encode($question));
                }
            }
        });
    }

    public function onHandshake(Request $request, Response $response) {
        if ($request->getHeader("origin") !== "http://localhost:1337") {
            $response->setStatus(403);
        }

        return $response;
    }

    public function onOpen(int $clientId, Request $request) {
        // do nothing
    }

    public function onData(int $clientId, Message $message) {
        // do nothing
    }

    public function onClose(int $clientId, int $code, string $reason) {
        // do nothing
    }

    public function onStop() {
        Loop::cancel($this->watcher);
    }
});

$router = new Router;
$router->addRoute("GET", "/live", $websocket);
$router->setFallback(new Root(__DIR__ . "/public"));

$server = new Server($router);
$server->expose("127.0.0.1", 1337);

Loop::run(function () use ($server) {
    yield $server->start();
});