<?php


namespace Aerys;

use Amp\Deferred;

class Rfc6455Endpoint implements WebsocketEndpoint, ServerObserver {
    private $application;
    private $reactor;
    private $proxy;
    private $state;
    private $clients = [];
    private $closeTimeouts = [];
    private $heartbeatTimeouts = [];
    private $timeoutWatcher;
    private $now;

    private $autoFrameSize = 32768;
    private $maxFrameSize = 2097152;
    private $maxMsgSize = 10485760;
    private $heartbeatPeriod = 10;
    private $closePeriod = 3;
    private $validateUtf8 = false;
    private $textOnly = false;
    private $queuedPingLimit = 3;
    // @TODO add minimum average frame size rate threshold to prevent tiny-frame DoS

    public function __construct(Websocket $application, Reactor $reactor = null) {
        $this->application = $application;
        $this->reactor = $reactor ?: reactor();
        $this->proxy = new Rfc6455EndpointProxy($this);
    }

    public function __invoke(Request $request, Response $response) {
        if ($request->method !== "GET") {
            $response->setStatus(HTTP_STATUS["METHOD_NOT_ALLOWED"]);
            $response->setHeader("Allow", "GET");
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
            return;
        }

        if ($request->protocol !== "1.1") {
            $response->setStatus(HTTP_STATUS["HTTP_VERSION_NOT_SUPPORTED"]);
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
            return;
        }

        if (empty($request->headers["UPGRADE"]) ||
            strcasecmp($request->headers["UPGRADE"], "websocket") !== 0
        ) {
            $response->setStatus(HTTP_STATUS["UPGRADE_REQUIRED"]);
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
            return;
        }

        if (empty($request->headers["CONNECTION"]) ||
            stripos($request->headers["CONNECTION"], "Upgrade") === FALSE
        ) {
            $response->setStatus(HTTP_STATUS["UPGRADE_REQUIRED"]);
            $response->setReason("Bad Request: \"Connection: Upgrade\" header required");
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
            return;
        }

        if (empty($request->headers["SEC_WEBSOCKET_KEY"])) {
            $response->setStatus(HTTP_STATUS["BAD_REQUEST"]);
            $response->setReason("Bad Request: \"Sec-Broker-Key\" header required");
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
            return;

        }

        if (empty($request->headers["SEC_WEBSOCKET_VERSION"])) {
            $response->setStatus(HTTP_STATUS["BAD_REQUEST"]);
            $response->setReason("Bad Request: \"Sec-WebSocket-Version\" header required");
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
            return;
        }

        $version = null;
        $requestedVersions = explode(',', $request->headers["SEC_WEBSOCKET_VERSION"]);
        foreach ($requestedVersions as $requestedVersion) {
            if ($requestedVersion === "13") {
                $version = 13;
                break;
            }
        }
        if (empty($version)) {
            $response->setStatus(HTTP_STATUS["BAD_REQUEST"]);
            $response->setReason("Bad Request: Requested WebSocket version(s) unavailable");
            $response->setHeader("Sec-WebSocket-Version", "13");
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
            return;
        }

        // Note that we shouldn't have to care here about a stopped server, this should happen in HTTP server with a 503 error
        return $this->import($request, $request);
    }

    private function import(Request $request, Response $response) {
        $client = new Rfc6455Client;
        $client->connectedAt = $request->time;

        $upgradePromisor = new Deferred;
        $acceptKey = $request->headers["SEC_WEBSOCKET_KEY"];
        $response->onUpgrade(function($socket, $refClearer) use ($client, $upgradePromisor) {
            $client->id = (int) $socket;
            $client->socket = $socket;
            $client->serverRefClearer = $refClearer;
            $upgradePromisor->succeed($wasUpgraded = true);
        });

        $handshaker = new WebsocketHandshake($upgradePromisor, $response, $acceptKey);

        $onHandshakeResult = $this->application->onHandshake($request, $handshaker);
        if ($onHandshakeResult instanceof \Generator) {
            $onHandshakeResult = yield from $onHandshakeResult;
        }
        $handshaker->end();
        if (!$wasUpgraded = yield $upgradePromisor->promise()) {
            return;
        }

        // ------ websocket upgrade complete -------

        $socket = $client->socket;
        $client->parser = new Rfc6455Parser([$this, "onParse"], $options = [
            "cb_data" => &$client
        ]);
        $client->readWatcher = $this->reactor->onReadable($socket, [$this, "onReadable"], $options = [
            "enable" => true,
            "cb_data" => $client,
        ]);
        $client->writeWatcher = $this->reactor->onWritable($socket, [$this, "onWritable"], $options = [
            "enable" => false,
            "cb_data" => $client,
        ]);

        // We initialize the closeRcvdPromisor now but wait to initialize the
        // closeSentPromisor so we can use its existence as a flag to know if
        // we've previously begun the close handshake.
        $client->closeRcvdPromisor = new Deferred;
        $client->closeSentPromisor = null;

        $this->clients[$client->id] = $client;

        yield from $this->tryAppOnOpen($clientId, $onHandshakeResult);
    }

    /**
     * Any subgenerator delegations here can safely use `yield from` because this
     * generator is invoked from the main import() function which is wrapped in a
     * resolve() at the HTTP server layer.
     */
    private function tryAppOnOpen(int $clientId, $onHandshakeResult): \Generator {
        try {
            $onOpenResult = $this->application->onOpen($clientId, $onHandshakeResult);
            if ($onOpenResult instanceof \Generator) {
                $onOpenResult = yield from $onOpenResult;
            }
        } catch (\Exception $e) {
            yield from $this->onAppError($clientId, $e);
        }
    }

    private function onAppError($clientId, \Exception $e): \Generator {
        $msg = $e->getMessage();
        error_log($msg);
        $code = WEBSOCKET_CODES["UNEXPECTED_SERVER_ERROR"];
        // RFC 6455 limits close reasons to 125 bytes
        $reason = isset($msg[125]) ? substr($msg, 0, 125) : $reason;
        yield from $this->doClose($clientId, $code, $reason);
    }

    private function doClose(Rfc6455Client $client, int $code, string $reason): \Generator {
        // Only proceed if we haven't already begun the close handshake elsewhere
        if ($client->closeSentPromisor) {
            return;
        }

        $client->closeSentPromisor = new Deferred;
        $this->sendCloseFrame($client, $code, $reason);
        yield $client->closeSentPromisor->promise();
        yield $client->closeRcvdPromisor->promise();
        yield from $this->tryAppOnClose($clientId, $code, $reason);
        // Don't unload the client until after onClose() invocation because
        // users may want to query the client's info
        $this->unloadClient($client);
    }

    private function unloadClient(Rfc6455Client $client) {
        $client->parser = null;
        $this->reactor->cancel($client->readWatcher);
        $this->reactor->cancel($client->writeWatcher);
        ($client->serverRefClearer)();
        unset($this->clients[$client->id]);
    }

    public function onParse(array $parseResult, Rfc6455Client $client) {
        switch ($parseResult[0]) {
            case Rfc6455Parser::CONTROL:
                $this->onParsedControlFrame($client, $parseResult);
                break;
            case Rfc6455Parser::DATA:
                $this->onParsedData($client, $parseResult);
                break;
            case Rfc6455Parser::ERROR:
                $this->onParsedError($client, $parseResult);
                break;
            default:
                assert(false, "Unknown Rfc6455Parser result code");
        }
    }
    
    private function onParsedControlFrame(Rfc6455Client $client, array $parseResult) {
        
    }
    
    private function onParsedData(Rfc6455Client $client, array $parseResult) {
        
    }
    
    private function onParsedError(Rfc6455Client $client, array $parseResult) {
        
    }

    public function onReadable($reactor, $watcherId, $socket, $client) {
        $data = @fread($socket, 8192);
        if ($data != "") {
            $client->parser->sink($data);
        } elseif (!is_resource($socket) || @feof($socket)) {
            // @TODO update this ... (currently uses http client code)
            $client->isDead = true;
            $this->close($client);
        }
    }

    public function onWritable($reactor, $watcherId, $socket, $client) {

    }

    public function send(string $data, int $clientId): Promise {

    }

    public function broadcast(string $data, array $clientIds = null): Promise {

    }

    public function close(int $clientId, int $code, string $reason = ""): Promise {

    }

    public function getInfo(int $clientId): array {
        if (!isset($this->clients[$clientId])) {
            return [];
        }
        $client = $this->clients[$clientId];

        return [
            'bytes_read'    => $client->bytesRead,
            'bytes_sent'    => $client->bytesSent,
            'frames_read'   => $client->framesRead,
            'frames_sent'   => $client->framesSent,
            'messages_read' => $client->messagesRead,
            'messages_sent' => $client->messagesSent,
            'connected_at'  => $client->connectedAt,
            'last_read_at'  => $client->lastReadAt,
            'last_send_at'  => $client->lastSendAt,
            'last_data_read_at'  => $client->lastDataReadAt,
            'last_data_send_at'  => $client->lastDataSendAt,
        ];
    }

    public function getClients(): array {
        return array_keys($this->clients);
    }

    public function update(\SplSubject $server): Promise {
        $this->state = $state = $server->state();
        switch ($state) {
            case Server::STARTING:
                return resolve($this->start());
            case Server::STARTED:
                $f = (new \ReflectionClass($this))->getMethod("timeout")->getClosure($this);
                $this->timeoutWatcher = $this->reactor->repeat($f, 1000);
                break;
            case Server::STOPPING:
                return resolve($this->stop());
            case Server::STOPPED:
                $this->reactor->cancel($this->timeoutWatcher);
                $this->timeoutWatcher = null;
                break;
        }

        return new Success;
    }

    private function timeout() {
        $this->now = $now = time();

        foreach ($this->heartbeatTimeouts as $clientId => $expiryTime) {
            if ($expiryTime < $now) {
                $client = $this->clients[$clientId];
                unset($this->heartbeatTimeouts[$clientId]);
                $this->heartbeatTimeouts[$clientId] = $now;
                $this->sendHeartbeatPing($client);
            } else {
                break;
            }
        }
        foreach ($this->closeTimeouts as $clientId => $expiryTime) {
            if ($expiryTime < $now) {
                $client = $this->clients[$clientId];
                $client->closeCode = Codes::ABNORMAL_CLOSE;
                $client->closeReason = 'CLOSE handshake timeout';
                $this->unloadSession($client);
            } else {
                break;
            }
        }
    }

    private function start(): \Generator {
        $result = $this->application->onStart($this->proxy);
        if ($result instanceof \Generator) {
            yield from $result;
        }
    }

    private function stop(): \Generator {
        $code = WEBSOCKET_CODES["GOING_AWAY"];
        $reason = "Server shutting down!";
        $promises = [];
        foreach ($this->clients as $client) {
            $promises[] = $this->close($client->id, $code, $reason);
        }
        yield any($promises);
        $result = $this->application->onStop();
        if ($result instanceof \Generator) {
            yield from $result;
        }
    }
}
