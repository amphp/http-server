<?php


namespace Aerys\Websocket;

use Amp\{
    Deferred,
    function resolve
};

use Aerys\{
    Request,
    Response,
    Server,
    ServerObserver,
    Websocket,
    const HTTP_STATUS
};

class Rfc6455Endpoint implements Endpoint, ServerObserver {
    private $application;
    private $reactor;
    private $proxy;
    private $state;
    private $clients = [];
    private $closeTimeouts = [];
    private $heartbeatTimeouts = [];
    private $timeoutWatcher;
    private $now;

    private $autoFrameSize = 32 << 10;
    private $maxFrameSize = 2 << 20;
    private $maxMsgSize = 10 << 20;
    private $heartbeatPeriod = 10;
    private $closePeriod = 3;
    private $validateUtf8 = false;
    private $textOnly = false;
    private $queuedPingLimit = 3;
    // @TODO add minimum average frame size rate threshold to prevent tiny-frame DoS

    const FRAME_END = 1;
    const MESSAGE_END = 2;
    const CONTROL_END = 3;

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

    private function import(Request $request, Response $response): \Generator {
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

        $handshaker = new Handshake($upgradePromisor, $response, $acceptKey);

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
            "cb_data" => $client
        ]);
        $client->readWatcher = $this->reactor->onReadable($socket, [$this, "onReadable"], $options = [
            "enable" => true,
            "cb_data" => $client,
        ]);
        $client->writeWatcher = $this->reactor->onWritable($socket, [$this, "onWritable"], $options = [
            "enable" => false,
            "cb_data" => $client,
        ]);

        $this->clients[$client->id] = $client;

        yield from $this->tryAppOnOpen($client->id, $onHandshakeResult);
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
        } catch (\BaseException $e) {
            yield from $this->onAppError($clientId, $e);
        }
    }

    private function onAppError($clientId, \BaseException $e): \Generator {
        $msg = $e->getMessage();
        error_log($msg);
        $code = CODES["UNEXPECTED_SERVER_ERROR"];
        // RFC 6455 limits close reasons to 125 bytes
        $reason = isset($msg[125]) ? substr($msg, 0, 125) : $reason;
        yield from $this->doClose($clientId, $code, $reason);
    }

    private function doClose(Rfc6455Client $client, int $code, string $reason): \Generator {
        // Only proceed if we haven't already begun the close handshake elsewhere
        if ($client->closedAt) {
            return;
        }

        $this->closeTimeouts[$client->id] = $this->now + $this->closePeriod;
        $this->sendCloseFrame($client, $code, $reason);
        yield from $this->tryAppOnClose($client->id, $code, $reason);
        // Don't unload the client here, it will be unloaded upon timeout
    }

    private function sendCloseFrame(Rfc6455Client $client, $code, $msg) {
        $client->closedAt = $this->now;
        $this->compile($client, pack('S', $code) . $msg, FRAME["OP_CLOSE"]);
    }

    private function tryAppOnClose(int $clientId, $code, $reason): \Generator {
        try {
            $onOpenResult = $this->application->onClose($clientId, $code, $reason);
            if ($onOpenResult instanceof \Generator) {
                $onOpenResult = yield from $onOpenResult;
            }
        } catch (\BaseException $e) {
            yield from $this->onAppError($clientId, $e);
        }
    }

    private function unloadClient(Rfc6455Client $client) {
        $client->parser = null;
        if ($client->readWatcher) {
            $this->reactor->cancel($client->readWatcher);
        }
        if ($client->writeWatcher) {
            $this->reactor->cancel($client->writeWatcher);
        }
        ($client->serverRefClearer)();
        unset($this->clients[$client->id]);

        // fail not yet terminated message streams; they *must not* be failed before client is removed
        if ($client->msgPromisor) {
            $client->msgPromisor->fail(new ClientException);
        }
    }

    public function onParse(array $parseResult, Rfc6455Client $client) {
        switch (array_shift($parseResult)) {
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
        list($data, $opcode) = $parseResult;

        switch ($opcode) {
            case FRAME["OP_CLOSE"]:
                if (!$client->closedAt) {
                    if (\strlen($data) < 2) {
                        return; // invalid close reason
                    }
                    $code = current(unpack('S', substr($data, 0, 2)));
                    $reason = substr($data, 2);

                    @stream_socket_shutdown($client->socket, STREAM_SHUT_WR);
                    $this->reactor->cancel($client->readWatcher);
                    $client->readWatcher = null;
                    resolve($this->doClose($client, $code, $reason));
                }
                break;

            case FRAME["OP_PING"]:
                $this->compile($client, $data, FRAME["OP_PONG"]);
                break;

            case FRAME["OP_PONG"]:
                $pendingPingCount = count($client->pendingPings);

                for ($i=$pendingPingCount - 1; $i >= 0; $i--) {
                    if ($client->pendingPings[$i] == $data) {
                        $client->pendingPings = array_slice($client->pendingPings, $i + 1);
                        break;
                    }
                }
                break;
        }
    }
    
    private function onParsedData(Rfc6455Client $client, array $parseResult) {
        $client->lastDataReadAt = $this->now;

        list($data, $terminated) = $parseResult;

        if (!$client->msgPromisor) {
            $client->msgPromisor = new Deferred;
            $msg = new WebsocketMessage($client->msgPromisor);
            $client->msgPromisor->update($data);

            if ($terminated) {
                $client->msgPromisor->succeed();
                $client->msgPromisor = null;
            }

            resolve($this->tryAppOnData($client, $msg));
        }
    }

    private function tryAppOnData(Rfc6455Client $client, Message $msg): \Generator {
        try {
            $gen = $this->application->onData($client->id, $msg);
            if ($gen instanceof \Generator) {
                yield from $gen;
            }
        } catch (\BaseException $e) {
            yield from $this->onAppError($client->id, $e);
        }
    }

    private function onParsedError(Rfc6455Client $client, array $parseResult) {
        list($msg, $code) = $parseResult;
        if ($code) {
            resolve($this->doClose($client, $code, $msg));
        }
    }

    public function onReadable($reactor, $watcherId, $socket, $client) {
        $data = @fread($socket, 8192);
        if ($data != "") {
            $client->lastReadAt = $this->now;
            $client->bytesRead += \strlen($data);
            $client->parser->sink($data);
        } elseif (!is_resource($socket) || @feof($socket)) {
            $client->closedAt = $this->now;
            resolve($this->tryAppOnClose($client->id, CODES["ABNORMAL_CLOSE"], "Client closed underlying TCP connection"));
            $this->unloadClient($client);
        }
    }

    public function onWritable($reactor, $watcherId, $socket, Rfc6455Client $client) {
retry:
        $bytes = @fwrite($socket, $client->writeBuffer);
        $client->bytesSent += $bytes;
        if ($bytes != \strlen($client->writeBuffer)) {
            $client->writeBuffer = substr($client->writeBuffer, $bytes);
        } else {
            $client->framesSent++;
            if ($client->writeControlQueue) {
                $client->writeBuffer = array_shift($client->writeControlQueue);
                $client->lastSentAt = $this->now;
                goto retry;
            } elseif ($client->writeDataQueue) {
                $client->writeBuffer = array_shift($client->writeDataQueue);
                $client->lastDataSentAt = $this->now;
                $client->lastSentAt = $this->now;
                goto retry;
            } elseif ($client->closedAt) {
                @stream_socket_shutdown($socket, STREAM_SHUT_WR);
                $reactor->cancel($watcherId);
                $client->writeWatcher = null;
            } else {
                $client->writeBuffer = "";
                $reactor->disable($watcherId);
            }
        }
    }

    private function compile(Rfc6455Client $client, string $msg, int $opcode = FRAME["OP_BIN"], bool $fin = true) {
        $frameInfo = ["msg" => $msg, "rsv" => 0b000, "fin" => $fin, "opcode" => $opcode];

        // @TODO filter mechanism …?! (e.g. gzip)
        foreach ($client->builder as $gen) {
            $gen->send($frameInfo);
            $gen->send(null);
            $frameInfo = $gen->current();
        }

        $this->write($frameInfo);
    }

    private function write(Rfc6455Client $client, $frameInfo) {
        $msg = $frameInfo["msg"];
        $len = strlen($msg);

        $w = chr(($frameInfo["fin"] << 7) | ($frameInfo["rsv"] << 4) | ($frameInfo["opcode"] << 1));
        if ($len > 0xFFFF) {
            $w .= "\x7F" . pack('J', $len);
        } elseif ($len > 0x7D) {
            $w .= "\x7E" . pack('n', $len);
        } else {
            $w .= chr($len);
        }

        $w .= $msg;

        $this->reactor->enable($client->writeWatcher);
        if ($client->writeBuffer != "") {
            if ($frameInfo["opcode"] >= 0x8) {
                $client->writeControlQueue[] = $w;
            } else {
                $client->writeDataQueue[] = $w;
            }
        } else {
            $client->writeBuffer = $w;
        }
    }

    // just a dummy builder ... no need to really use it
    private function defaultBuilder(Rfc6455Client $client) {
        $yield = yield;
        while (1) {
            $data = [];
            $frameInfo = $yield;
            $data[] = $yield["msg"];

            while (($yield = yield) !== null); {
                $data[] = $yield;
            }

            $msg = count($data) == 1 ? $data[0] : implode($data);
            $yield = yield $msg + $frameInfo;
        }
    }

    public function send(string $data, int $clientId) {
        if ($client = $this->clients[$clientId] ?? null) {
            $opcode = FRAME["OP_BIN"];

            if (strlen($data) > 1.5 * $this->autoFrameSize) {
                $len = strlen($data);
                $slices = ceil($len / $this->autoFrameSize);
                $frames = str_split($data, ceil($len / $slices));
                foreach ($frames as $frame) {
                    $this->compile($client, $frame, $opcode, false);
                    $opcode = FRAME["OP_CONT"];
                }
            }
            $this->compile($client, $data, $opcode);
        }
    }

    public function broadcast(string $data, array $clientIds = null) {
        if ($clientIds === null) {
            $clientIds = array_keys($this->clients);
        }

        foreach ($clientIds as $clientId) {
            $this->send($data, $clientId);
        }
    }

    public function close(int $clientId, int $code = CODES["NORMAL_CLOSE"], string $reason = "") {
        if (isset($this->clients[$clientId])) {
            resolve($this->doClose($this->clients[$clientId], $code, $reason));
        }
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
            'closed_at'     => $client->closedAt,
            'last_read_at'  => $client->lastReadAt,
            'last_sent_at'  => $client->lastSentAt,
            'last_data_read_at'  => $client->lastDataReadAt,
            'last_data_sent_at'  => $client->lastDataSentAt,
        ];
    }

    public function getClients(): array {
        return array_keys($this->clients);
    }

    public function update(\SplSubject $server): Promise {
        $this->state = $state = $server->state();
        switch ($state) {
            case Server::STARTING:
                $result = $this->application->onStart($this->proxy);
                if ($result instanceof \Generator) {
                    resolve($result);
                }
                break;
            case Server::STARTED:
                $f = (new \ReflectionClass($this))->getMethod("timeout")->getClosure($this);
                $this->timeoutWatcher = $this->reactor->repeat($f, 1000);
                break;
            case Server::STOPPING:
                $code = CODES["GOING_AWAY"];
                $reason = "Server shutting down!";

                foreach ($this->clients as $client) {
                    $this->close($client->id, $code, $reason);
                }

                $result = $this->application->onStop();
                if ($result instanceof \Generator) {
                    resolve($result);
                }
            case Server::STOPPED:
                $this->reactor->cancel($this->timeoutWatcher);
                $this->timeoutWatcher = null;

                foreach ($this->closeTimeouts as $clientId => $expiryTime) {
                    $this->unloadClient($this->clients[$clientId]);
                }
                break;
        }

        return new Success;
    }

    private function sendHeartbeatPing(Rfc6455Client $client) {
        $ord = rand(48, 90);
        $data = chr($ord);

        if (array_push($client->pendingPings, $data) > $this->queuedPingLimit) {
            $code = CODES["POLICY_VIOLATION"];
            $reason = 'Exceeded unanswered PING limit';
            $this->doClose($client, $code, $reason);
        } else {
            $this->compile($client, $data, FRAME["OP_PING"]);
        }
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
                $this->unloadClient($this->clients[$clientId]);
            } else {
                break;
            }
        }
    }
}
