<?php

namespace Aerys\Websocket;

use Amp\{
    Deferred,
    Failure,
    Promise,
    Success,
    function all,
    function any,
    function reactor,
    function resolve
};

use Aerys\{
    ClientException,
    InternalRequest,
    Middleware,
    Monitor,
    NullBody,
    Request,
    Response,
    Server,
    ServerObserver,
    Websocket,
    const HTTP_STATUS
};

use Psr\Log\LoggerInterface as PsrLogger;

class Rfc6455Endpoint implements Middleware, Monitor, ServerObserver {
    private $logger;
    private $application;
    private $proxy;
    private $state;
    private $clients = [];
    private $lowCapacityClients = [];
    private $highFramesPerSecondClients = [];
    private $closeTimeouts = [];
    private $heartbeatTimeouts = [];
    private $timeoutWatcher;
    private $now;

    private $autoFrameSize = (64 << 10) - 9 /* frame overhead */;
    private $maxBytesPerMinute = 8 << 20;
    private $maxFrameSize = 2 << 20;
    private $maxMsgSize = 2 << 20;
    private $heartbeatPeriod = 10;
    private $closePeriod = 3;
    private $validateUtf8 = false;
    private $textOnly = false;
    private $queuedPingLimit = 3;
    private $maxFramesPerSecond = 100; // do not bother with setting it too low, fread(8192) may anyway include up to 2700 frames

    /* Frame control bits */
    const FIN      = 0b1;
    const RSV_NONE = 0b000;
    const OP_CONT  = 0x00;
    const OP_TEXT  = 0x01;
    const OP_BIN   = 0x02;
    const OP_CLOSE = 0x08;
    const OP_PING  = 0x09;
    const OP_PONG  = 0x0A;

    const CONTROL = 1;
    const DATA = 2;
    const ERROR = 3;

    public function __construct(PsrLogger $logger, Websocket $application) {
        $this->logger = $logger;
        $this->application = $application;
        $this->now = time();
        $this->proxy = new Rfc6455EndpointProxy($this);
    }

    public function setOption(string $option, $value) {
        switch ($option) {
            case "maxBytesPerMinute":
                if (8192 > $value) {
                    throw new \DomainException("$option must be at least 8192 bytes");
                }
            case "autoFrameSize":
            case "maxFrameSize":
            case "maxFramesPerSecond":
            case "maxMsgSize":
            case "heartbeatPeriod":
            case "closePeriod":
            case "queuedPingLimit":
                if (0 <= $value = filter_var($value, FILTER_VALIDATE_INT)) {
                    throw new \DomainException("$option must be a positive integer greater than 0");
                }
                break;
            case "validateUtf8":
            case "textOnly":
                if (null === $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) {
                    throw new \DomainException("$option must be a boolean value");
                }
                break;
            default:
                throw new \DomainException("Unknown option $option");
        }
        $this->$option = $value;
    }

    public function __invoke(Request $request, Response $response, ...$args) {
        if ($request->getMethod() !== "GET") {
            $response->setStatus(HTTP_STATUS["METHOD_NOT_ALLOWED"]);
            $response->setHeader("Allow", "GET");
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
            return;
        }

        if ($request->getProtocolVersion() !== "1.1") {
            $response->setStatus(HTTP_STATUS["HTTP_VERSION_NOT_SUPPORTED"]);
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
            return;
        }

        $body = $request->getBody();
        if (!$body instanceof NullBody) {
            $response->setStatus(HTTP_STATUS["BAD_REQUEST"]);
            $response->setReason("Bad Request: Entity body disallowed for websocket endpoint");
            $response->setHeader("Connection", "close");
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
            return;
        }

        $hasUpgradeWebsocket = false;
        foreach ($request->getHeaderArray("Upgrade") as $value) {
            if (strcasecmp($value, "websocket") === 0) {
                $hasUpgradeWebsocket = true;
                break;
            }
        }
        if (empty($hasUpgradeWebsocket)) {
            $response->setStatus(HTTP_STATUS["UPGRADE_REQUIRED"]);
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
            return;
        }

        $hasConnectionUpgrade = false;
        foreach ($request->getHeaderArray("Connection") as $value) {
            $values = array_map("trim", explode(",", $value));

            foreach ($values as $token) {
                if (strcasecmp($token, "Upgrade") === 0) {
                    $hasConnectionUpgrade = true;
                    break;
                }
            }
        }
        if (empty($hasConnectionUpgrade)) {
            $response->setStatus(HTTP_STATUS["UPGRADE_REQUIRED"]);
            $response->setReason("Bad Request: \"Connection: Upgrade\" header required");
            $response->setHeader("Upgrade", "websocket");
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
            return;
        }

        if (!$acceptKey = $request->getHeader("Sec-Websocket-Key")) {
            $response->setStatus(HTTP_STATUS["BAD_REQUEST"]);
            $response->setReason("Bad Request: \"Sec-Websocket-Key\" header required");
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
            return;

        }

        if (!in_array("13", $request->getHeaderArray("Sec-Websocket-Version"))) {
            $response->setStatus(HTTP_STATUS["BAD_REQUEST"]);
            $response->setReason("Bad Request: Requested Websocket version unavailable");
            $response->setHeader("Sec-WebSocket-Version", "13");
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
            return;
        }

        $handshaker = new Handshake($response, $acceptKey);

        $onHandshakeResult = $this->application->onHandshake($request, $handshaker, ...$args);
        if ($onHandshakeResult instanceof \Generator) {
            $onHandshakeResult = yield from $onHandshakeResult;
        }
        $request->setLocalVar("aerys.websocket", $onHandshakeResult);
        $handshaker->end();
    }

    public function do(InternalRequest $ireq) {
        $headers = yield;
        if ($headers[":status"] == 101) {
            $yield = yield $headers;
        } else {
            return $headers; // detach if we don't want to establish websocket connection
        }

        while ($yield !== null) {
            $yield = yield $yield;
        }

        \Amp\immediately([$this, "reapClient"], ["cb_data" => $ireq]);
    }

    public function reapClient($watcherId, InternalRequest $ireq) {
        $client = new Rfc6455Client;
        $client->capacity = $this->maxBytesPerMinute;
        $client->connectedAt = $this->now;
        $socket = $ireq->client->socket;
        $client->id = (int) $socket;
        $client->socket = $socket;
        $client->writeBuffer = $ireq->client->writeBuffer;
        $client->serverRefClearer = ($ireq->client->exporter)($ireq->client);

        $client->parser = $this->parser([$this, "onParse"], $options = [
            "cb_data" => $client,
            "max_msg_size" => $this->maxMsgSize,
            "max_frame_size" => $this->maxFrameSize,
            "validate_utf8" => $this->validateUtf8,
            "text_only" => $this->textOnly,
            "threshold" => $this->autoFrameSize,
        ]);
        $client->readWatcher = \Amp\onReadable($socket, [$this, "onReadable"], $options = [
            "enable" => true,
            "cb_data" => $client,
        ]);
        $hasBuffer = $client->writeBuffer != "";
        $client->writeWatcher = \Amp\onWritable($socket, [$this, "onWritable"], $options = [
            "enable" => $hasBuffer,
            "cb_data" => $client,
        ]);
        if ($hasBuffer) {
            $client->writeDeferred = new Deferred; // dummy to prevent error
            $client->framesSent = -1;
        }

        $this->clients[$client->id] = $client;
        $this->heartbeatTimeouts[$client->id] = $this->now + $this->heartbeatPeriod;

        resolve($this->tryAppOnOpen($client->id, $ireq->locals["aerys.websocket"]));

        return $client;
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
        } catch (\Throwable $e) {
            yield from $this->onAppError($clientId, $e);
        }
    }

    private function onAppError($clientId, \Throwable $e): \Generator {
        $this->logger->error($e->__toString());
        $code = Code::UNEXPECTED_SERVER_ERROR;
        $reason = "Internal server error, aborting";
        if (isset($this->clients[$clientId])) { // might have been already unloaded + closed
            yield from $this->doClose($this->clients[$clientId], $code, $reason);
        }
    }

    private function doClose(Rfc6455Client $client, int $code, string $reason): \Generator {
        // Only proceed if we haven't already begun the close handshake elsewhere
        if ($client->closedAt) {
            return;
        }

        $this->closeTimeouts[$client->id] = $this->now + $this->closePeriod;
        $promise = $this->sendCloseFrame($client, $code, $reason);
        yield from $this->tryAppOnClose($client->id, $code, $reason);
        return $promise;
        // Don't unload the client here, it will be unloaded upon timeout or last data written if closed by client or OP_CLOSE received by client
    }

    private function sendCloseFrame(Rfc6455Client $client, $code, $msg): Promise {
        \assert($code !== Code::NONE || $msg == "");
        $promise = $this->compile($client, $code !== Code::NONE ? pack('n', $code) . $msg : "", self::OP_CLOSE);
        $client->closedAt = $this->now;
        return $promise;
    }

    private function tryAppOnClose(int $clientId, $code, $reason): \Generator {
        try {
            $onCloseResult = $this->application->onClose($clientId, $code, $reason);
            if ($onCloseResult instanceof \Generator) {
                $onCloseResult = yield from $onCloseResult;
            }
        } catch (\Throwable $e) {
            yield from $this->onAppError($clientId, $e);
        }
    }

    private function unloadClient(Rfc6455Client $client) {
        $client->parser = null;
        if ($client->readWatcher) {
            \Amp\cancel($client->readWatcher);
        }
        if ($client->writeWatcher) {
            \Amp\cancel($client->writeWatcher);
        }

        unset($this->heartbeatTimeouts[$client->id]);
        ($client->serverRefClearer)();
        unset($this->clients[$client->id]);

        // fail not yet terminated message streams; they *must not* be failed before client is removed
        if ($client->msgPromisor) {
            $client->msgPromisor->fail(new ClientException);
        }

        if ($client->writeBuffer != "") {
            $client->writeDeferred->fail(new ClientException);
        }
        foreach ([$client->writeDeferredDataQueue, $client->writeDeferredControlQueue] as $deferreds) {
            foreach ($deferreds as $deferred) {
                $deferred->fail(new ClientException);
            }
        }
    }

    public function onParse(array $parseResult, Rfc6455Client $client) {
        switch (array_shift($parseResult)) {
            case self::CONTROL:
                $this->onParsedControlFrame($client, $parseResult);
                break;
            case self::DATA:
                $this->onParsedData($client, $parseResult);
                break;
            case self::ERROR:
                $this->onParsedError($client, $parseResult);
                break;
            default:
                assert(false, "Unknown Rfc6455Parser result code");
        }
    }

    private function onParsedControlFrame(Rfc6455Client $client, array $parseResult) {
        // something went that wrong that we had to shutdown our readWatcher... if parser has anything left, we don't care!
        if (!$client->readWatcher) {
            return;
        }

        list($data, $opcode) = $parseResult;

        switch ($opcode) {
            case self::OP_CLOSE:
                if ($client->closedAt) {
                    unset($this->closeTimeouts[$client->id]);
                    $this->unloadClient($client);
                } else {
                    if (\strlen($data) < 2) {
                        $code = Code::NONE;
                        $reason = "";
                    } else {
                        $code = current(unpack('n', substr($data, 0, 2)));
                        $reason = substr($data, 2);
                    }

                    @stream_socket_shutdown($client->socket, STREAM_SHUT_RD);
                    \Amp\cancel($client->readWatcher);
                    $client->readWatcher = null;
                    resolve($this->doClose($client, $code, $reason));
                }
                break;

            case self::OP_PING:
                $this->compile($client, $data, self::OP_PONG);
                break;

            case self::OP_PONG:
                // We need a min() here, else someone might just send a pong frame with a very high pong count and leave TCP connection in open state... Then we'd accumulate connections which never are cleaned up...
                $client->pongCount = min($client->pingCount, $data);
                break;
        }
    }

    private function onParsedData(Rfc6455Client $client, array $parseResult) {
        if ($client->closedAt || $this->state === Server::STOPPING) {
            return;
        }

        $client->lastDataReadAt = $this->now;

        list($data, $terminated) = $parseResult;

        if (!$client->msgPromisor) {
            $client->msgPromisor = new Deferred;
            $msg = new Message($client->msgPromisor->promise());
            resolve($this->tryAppOnData($client, $msg));
        }

        $client->msgPromisor->update($data);
        if ($terminated) {
            $client->msgPromisor->succeed();
            $client->msgPromisor = null;
        }

        $client->messagesRead += $terminated;
    }

    private function tryAppOnData(Rfc6455Client $client, Message $msg): \Generator {
        try {
            $gen = $this->application->onData($client->id, $msg);
            if ($gen instanceof \Generator) {
                yield from $gen;
            }
        } catch (ClientException $e) {
        } catch (\Throwable $e) {
            yield from $this->onAppError($client->id, $e);
        }
    }

    private function onParsedError(Rfc6455Client $client, array $parseResult) {
        // something went that wrong that we had to shutdown our readWatcher... if parser has anything left, we don't care!
        if (!$client->readWatcher) {
            return;
        }

        list($msg, $code) = $parseResult;

        if ($code) {
            if ($client->closedAt || $code == Code::PROTOCOL_ERROR) {
                @stream_socket_shutdown($client->socket, STREAM_SHUT_RD);
                \Amp\cancel($client->readWatcher);
                $client->readWatcher = null;
            }

            if (!$client->closedAt) {
                resolve($this->doClose($client, $code, $msg));
            }
        }
    }

    public function onReadable($watcherId, $socket, Rfc6455Client $client) {
        $data = @fread($socket, 8192);

        if ($data != "") {
            $client->lastReadAt = $this->now;
            $client->bytesRead += \strlen($data);
            $client->capacity -= \strlen($data);
            if ($client->capacity < $this->maxBytesPerMinute / 2) {
                $this->lowCapacityClients[$client->id] = $client;
                if ($client->capacity <= 0) {
                    \Amp\disable($watcherId);
                }
            }
            $frames = $client->parser->send($data);
            $client->framesRead += $frames;
            $client->framesLastSecond += $frames;
            if ($client->framesLastSecond > $this->maxFramesPerSecond / 2) {
                if ($client->framesLastSecond > $this->maxFramesPerSecond * 1.5) {
                    \Amp\disable($watcherId); // aka tiny frame DoS prevention
                }
                $this->highFramesPerSecondClients[$client->id] = $client;
            }
        } elseif (!is_resource($socket) || @feof($socket)) {
            if (!$client->closedAt) {
                $client->closedAt = $this->now;
                $code = Code::ABNORMAL_CLOSE;
                $reason = "Client closed underlying TCP connection";
                resolve($this->tryAppOnClose($client->id, $code, $reason));
            } else {
                unset($this->closeTimeouts[$client->id]);
            }

            $this->unloadClient($client);
        }
    }

    public function onWritable($watcherId, $socket, Rfc6455Client $client) {
        $bytes = @fwrite($socket, $client->writeBuffer);
        $client->bytesSent += $bytes;

        if ($bytes != \strlen($client->writeBuffer)) {
            $client->writeBuffer = substr($client->writeBuffer, $bytes);
        } elseif ($bytes == 0 && $client->closedAt && (!is_resource($socket) || @feof($socket))) {
            // usually read watcher cares about aborted TCP connections, but when
            // $client->closedAt is true, it might be the case that read watcher
            // is already cancelled and we need to ensure that our writing promise
            // is fulfilled in unloadClient() with a failure
            unset($this->closeTimeouts[$client->id]);
            $this->unloadClient($client);
        } else {
            $client->framesSent++;
            $client->writeDeferred->succeed();
            if ($client->writeControlQueue) {
                $key = key($client->writeControlQueue);
                $client->writeBuffer = $client->writeControlQueue[$key];
                $client->lastSentAt = $this->now;
                $client->writeDeferred = $client->writeDeferredControlQueue[$key];
                unset($client->writeControlQueue[$key], $client->writeDeferredControlQueue[$key]);
                while (\strlen($client->writeBuffer) < 65536 && $client->writeControlQueue) {
                    $key = key($client->writeControlQueue);
                    $client->writeBuffer .= $client->writeControlQueue[$key];
                    $client->writeDeferredControlQueue[$key]->succeed($client->writeDeferred);
                    unset($client->writeControlQueue[$key], $client->writeDeferredControlQueue[$key]);
                }
                while (\strlen($client->writeBuffer) < 65536 && $client->writeDataQueue) {
                    $key = key($client->writeDataQueue);
                    $client->writeBuffer .= $client->writeDataQueue[$key];
                    $client->writeDeferredDataQueue[$key]->succeed($client->writeDeferred);
                    unset($client->writeDataQueue[$key], $client->writeDeferredDataQueue[$key]);
                }

            } elseif ($client->closedAt) {
                $client->writeBuffer = "";
                $client->writeDeferred = null;
                if ($client->readWatcher) { // readWatcher exists == we are still waiting for an OP_CLOSE frame
                    @stream_socket_shutdown($socket, STREAM_SHUT_WR);
                    \Amp\cancel($watcherId);
                    $client->writeWatcher = null;
                } else {
                    unset($this->closeTimeouts[$client->id]);
                    $this->unloadClient($client);
                }
            } elseif ($client->writeDataQueue) {
                $key = key($client->writeDataQueue);
                $client->writeBuffer = $client->writeDataQueue[$key];
                $client->lastDataSentAt = $this->now;
                $client->lastSentAt = $this->now;
                $client->writeDeferred = $client->writeDeferredDataQueue[$key];
                unset($client->writeDataQueue[$key], $client->writeDeferredDataQueue[$key]);
                while (\strlen($client->writeBuffer) < 65536 && $client->writeDataQueue) {
                    $key = key($client->writeDataQueue);
                    $client->writeBuffer .= $client->writeDataQueue[$key];
                    $client->writeDeferredDataQueue[$key]->succeed($client->writeDeferred);
                    unset($client->writeDataQueue[$key], $client->writeDeferredDataQueue[$key]);
                }
            } else {
                $client->writeDeferred = null;
                $client->writeBuffer = "";
                \Amp\disable($watcherId);
            }
        }
    }

    private function compile(Rfc6455Client $client, string $msg, int $opcode, bool $fin = true): Promise {
        $frameInfo = ["msg" => $msg, "rsv" => 0b000, "fin" => $fin, "opcode" => $opcode];

        // @TODO filter mechanism …?! (e.g. gzip)
        foreach ($client->builder as $gen) {
            $gen->send($frameInfo);
            $gen->send(null);
            $frameInfo = $gen->current();
        }

        return $this->write($client, $frameInfo);
    }

    private function write(Rfc6455Client $client, $frameInfo): Promise {
        if ($client->closedAt) {
            return new Failure(new ClientException);
        }

        $msg = $frameInfo["msg"];
        $len = \strlen($msg);

        $w = chr(($frameInfo["fin"] << 7) | ($frameInfo["rsv"] << 4) | $frameInfo["opcode"]);

        if ($len > 0xFFFF) {
            $w .= "\x7F" . pack('J', $len);
        } elseif ($len > 0x7D) {
            $w .= "\x7E" . pack('n', $len);
        } else {
            $w .= chr($len);
        }

        $w .= $msg;

        if ($client->writeBuffer != "") {
            if (\strlen($client->writeBuffer) < 65536 && !$client->writeDataQueue && !$client->writeControlQueue) {
                $client->writeBuffer .= $w;
                $deferred = $client->writeDeferred;
            } elseif ($frameInfo["opcode"] >= 0x8) {
                $client->writeControlQueue[] = $w;
                $deferred = $client->writeDeferredControlQueue[] = new Deferred;
            } else {
                $client->writeDataQueue[] = $w;
                $deferred = $client->writeDeferredDataQueue[] = new Deferred;
            }
        } else {
            \Amp\enable($client->writeWatcher);
            $client->writeBuffer = $w;
            $deferred = $client->writeDeferred = new Deferred;
        }

        return $deferred->promise();
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

    public function send(string $data, bool $binary, int $clientId): Promise {
        if (!isset($this->clients[$clientId])) {
            return new Success;
        }

        $client = $this->clients[$clientId];
        $client->messagesSent++;
        $opcode = $binary ? self::OP_BIN : self::OP_TEXT;
        assert($binary || preg_match("//u", $data), "non-binary data needs to be UTF-8 compatible");

        if (\strlen($data) > 1.5 * $this->autoFrameSize) {
            $len = \strlen($data);
            $slices = ceil($len / $this->autoFrameSize);
            $frames = str_split($data, ceil($len / $slices));
            $data = array_pop($frames);
            foreach ($frames as $frame) {
                $this->compile($client, $frame, $opcode, false);
                $opcode = self::OP_CONT;
            }
        }
        return $this->compile($client, $data, $opcode);
    }

    public function broadcast(string $data, bool $binary, array $exceptIds = []): Promise {
        $promises = [];
        if (empty($exceptIds)) {
            foreach ($this->clients as $id => $client) {
                $promises[] = $this->send($data, $binary, $id);
            }
        } else {
            $exceptIds = \array_flip($exceptIds);
            foreach ($this->clients as $id => $client) {
                if (isset($exceptIds[$id])) {
                    continue;
                }
                $promises[] = $this->send($data, $binary, $id);
            }
        }
        return all($promises);
    }

    public function multicast(string $data, bool $binary, array $clientIds): Promise {
        $promises = [];
        foreach ($clientIds as $id) {
            $promises[] = $this->send($data, $binary, $id);
        }
        return all($promises);
    }

    public function close(int $clientId, int $code = Code::NORMAL_CLOSE, string $reason = "") {
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

    public function update(Server $server): Promise {
        switch ($this->state = $server->state()) {
            case Server::STARTING:
                $result = $this->application->onStart($this->proxy);
                if ($result instanceof \Generator) {
                    return resolve($result);
                }
                break;

            case Server::STARTED:
                $f = (new \ReflectionClass($this))->getMethod("timeout")->getClosure($this);
                $this->timeoutWatcher = \Amp\repeat($f, 1000);
                break;

            case Server::STOPPING:
                $result = $this->application->onStop();
                if ($result instanceof \Generator) {
                    $promise = resolve($result);
                } elseif ($result instanceof Promise) {
                    $promise = $result;
                } else {
                    $promise = new Success;
                }

                $promise->when(function () {
                    $code = Code::GOING_AWAY;
                    $reason = "Server shutting down!";

                    foreach ($this->clients as $client) {
                        $this->close($client->id, $code, $reason);
                    }
                });

                \Amp\cancel($this->timeoutWatcher);
                $this->timeoutWatcher = null;

                return $promise;

            case Server::STOPPED:
                $promises = [];

                // we are not going to wait for a proper self::OP_CLOSE answer (because else we'd need to timeout for 3 seconds, not worth it), but we will ensure to at least *have written* it
                foreach ($this->clients as $client) {
                    // only if we couldn't successfully send it in STOPPING
                    $code = Code::GOING_AWAY;
                    $reason = "Server shutting down!";

                    $result = $this->doClose($client, $code, $reason);
                    if ($result instanceof \Generator) {
                        $promises[] = resolve($result);
                    }

                    if (!empty($client->writeDeferredControlQueue)) {
                        $promise = end($client->writeDeferredControlQueue)->promise();
                        if ($promise) {
                            $promises[] = $promise;
                        }
                    }
                }
                $promise = any($promises);
                $promise->when(function () {
                    foreach ($this->clients as $client) {
                        $this->unloadClient($client);
                    }
                });

                return $promise;
        }

        return new Success;
    }

    private function sendHeartbeatPing(Rfc6455Client $client) {
        if ($client->pingCount - $client->pongCount > $this->queuedPingLimit) {
            $code = Code::POLICY_VIOLATION;
            $reason = 'Exceeded unanswered PING limit';
            $this->doClose($client, $code, $reason);
        } else {
            $this->compile($client, $client->pingCount++, self::OP_PING);
        }
    }

    private function timeout() {
        $this->now = $now = time();

        foreach ($this->closeTimeouts as $clientId => $expiryTime) {
            if ($expiryTime < $now) {
                $this->unloadClient($this->clients[$clientId]);
                unset($this->closeTimeouts[$clientId]);
            } else {
                break;
            }
        }

        foreach ($this->heartbeatTimeouts as $clientId => $expiryTime) {
            if ($expiryTime < $now) {
                $client = $this->clients[$clientId];
                unset($this->heartbeatTimeouts[$clientId]);
                $this->heartbeatTimeouts[$clientId] = $now + $this->heartbeatPeriod;
                $this->sendHeartbeatPing($client);
            } else {
                break;
            }
        }

        foreach ($this->lowCapacityClients as $id => $client) {
            $client->capacity += $this->maxBytesPerMinute / 60;
            if ($client->capacity > $this->maxBytesPerMinute) {
                unset($this->lowCapacityClients[$id]);
            }
            if ($client->capacity > 8192 && !isset($this->highFramesPerSecondClients[$id])) {
                \Amp\enable($client->readWatcher);
            }
        }

        foreach ($this->highFramesPerSecondClients as $id => $client) {
            $client->framesLastSecond -= $this->maxFramesPerSecond;
            if ($client->framesLastSecond < $this->maxFramesPerSecond) {
                unset($this->highFramesPerSecondClients[$id]);
                if ($client->capacity > 8192) {
                    \Amp\enable($client->readWatcher);
                }
            }
        }
    }

    /**
     * A stateful generator websocket frame parser
     *
     * @param callable $emitCallback A callback to receive parser event emissions
     * @param array $options Optional parser settings
     * @return \Generator
     */
    static public function parser(callable $emitCallback, array $options = []): \Generator {
        $callbackData = $options["cb_data"] ?? null;
        $emitThreshold = $options["threshold"] ?? 32768;
        $maxFrameSize = $options["max_frame_size"] ?? PHP_INT_MAX;
        $maxMsgSize = $options["max_msg_size"] ?? PHP_INT_MAX;
        $textOnly = $options["text_only"] ?? false;
        $doUtf8Validation = $validateUtf8 = $options["validate_utf8"] ?? false;

        $dataMsgBytesRecd = 0;
        $nextEmit = $emitThreshold;
        $dataArr = [];

        $buffer = yield;
        $offset = 0;
        $bufferSize = \strlen($buffer);
        $frames = 0;

        while (1) {
            if ($bufferSize < 2) {
                $buffer = substr($buffer, $offset);
                $offset = 0;
                do {
                    $buffer .= yield $frames;
                    $bufferSize = \strlen($buffer);
                    $frames = 0;
                } while ($bufferSize < 2);
            }

            $firstByte = ord($buffer[$offset]);
            $secondByte = ord($buffer[$offset + 1]);

            $offset += 2;
            $bufferSize -= 2;

            $fin = (bool)($firstByte & 0b10000000);
            // $rsv = ($firstByte & 0b01110000) >> 4; // unused (let's assume the bits are all zero)
            $opcode = $firstByte & 0b00001111;
            $isMasked = (bool)($secondByte & 0b10000000);
            $maskingKey = null;
            $frameLength = $secondByte & 0b01111111;

            $isControlFrame = $opcode >= 0x08;
            if ($validateUtf8 && $opcode !== self::OP_CONT && !$isControlFrame) {
                $doUtf8Validation = $opcode === self::OP_TEXT;
            }

            if ($frameLength === 0x7E) {
                if ($bufferSize < 2) {
                    $buffer = substr($buffer, $offset);
                    $offset = 0;
                    do {
                        $buffer .= yield $frames;
                        $bufferSize = \strlen($buffer);
                        $frames = 0;
                    } while ($bufferSize < 2);
                }

                $frameLength = unpack('n', $buffer[$offset] . $buffer[$offset + 1])[1];
                $offset += 2;
                $bufferSize -= 2;
            } elseif ($frameLength === 0x7F) {
                if ($bufferSize < 8) {
                    $buffer = substr($buffer, $offset);
                    $offset = 0;
                    do {
                        $buffer .= yield $frames;
                        $bufferSize = \strlen($buffer);
                        $frames = 0;
                    } while ($bufferSize < 8);
                }

                $lengthLong32Pair = unpack('N2', substr($buffer, $offset, 8));
                $offset += 8;
                $bufferSize -= 8;

                if (PHP_INT_MAX === 0x7fffffff) {
                    if ($lengthLong32Pair[1] !== 0 || $lengthLong32Pair[2] < 0) {
                        $code = Code::MESSAGE_TOO_LARGE;
                        $errorMsg = 'Payload exceeds maximum allowable size';
                        break;
                    }
                    $frameLength = $lengthLong32Pair[2];
                } else {
                    $frameLength = ($lengthLong32Pair[1] << 32) | $lengthLong32Pair[2];
                    if ($frameLength < 0) {
                        $code = Code::PROTOCOL_ERROR;
                        $errorMsg = 'Most significant bit of 64-bit length field set';
                        break;
                    }
                }
            }

            if ($frameLength > 0 && !$isMasked) {
                $code = Code::PROTOCOL_ERROR;
                $errorMsg = 'Payload mask required';
                break;
            } elseif ($isControlFrame) {
                if (!$fin) {
                    $code = Code::PROTOCOL_ERROR;
                    $errorMsg = 'Illegal control frame fragmentation';
                    break;
                } elseif ($frameLength > 125) {
                    $code = Code::PROTOCOL_ERROR;
                    $errorMsg = 'Control frame payload must be of maximum 125 bytes or less';
                    break;
                }
            } elseif (($opcode === 0x00) === ($dataMsgBytesRecd === 0)) {
                // We deliberately do not accept a non-fin empty initial text frame
                $code = Code::PROTOCOL_ERROR;
                if ($opcode === 0x00) {
                    $errorMsg = 'Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY';
                } else {
                    $errorMsg = 'Illegal data type opcode after unfinished previous data type frame; opcode MUST be CONTINUATION';
                }
                break;
            } elseif ($maxFrameSize && $frameLength > $maxFrameSize) {
                $code = Code::MESSAGE_TOO_LARGE;
                $errorMsg = 'Payload exceeds maximum allowable frame size';
                break;
            } elseif ($maxMsgSize && ($frameLength + $dataMsgBytesRecd) > $maxMsgSize) {
                $code = Code::MESSAGE_TOO_LARGE;
                $errorMsg = 'Payload exceeds maximum allowable message size';
                break;
            } elseif ($textOnly && $opcode === 0x02) {
                $code = Code::UNACCEPTABLE_TYPE;
                $errorMsg = 'BINARY opcodes (0x02) not accepted';
                break;
            }

            if ($isMasked) {
                if ($bufferSize < 4) {
                    $buffer = substr($buffer, $offset);
                    $offset = 0;
                    do {
                        $buffer .= yield $frames;
                        $bufferSize = \strlen($buffer);
                        $frames = 0;
                    } while ($bufferSize < 4);
                }

                $maskingKey = substr($buffer, $offset, 4);
                $offset += 4;
                $bufferSize -= 4;
            }

            if ($bufferSize >= $frameLength) {
                if (!$isControlFrame) {
                    $dataMsgBytesRecd += $frameLength;
                }

                $payload = substr($buffer, $offset, $frameLength);
                $offset += $frameLength;
                $bufferSize -= $frameLength;
            } else {
                if (!$isControlFrame) {
                    $dataMsgBytesRecd += $bufferSize;
                }
                $frameBytesRecd = $bufferSize;

                $payload = substr($buffer, $offset);

                do {
                    // if we want to validate UTF8, we must *not* send incremental mid-frame updates because the message might be broken in the middle of an utf-8 sequence
                    // also, control frames always are <= 125 bytes, so we never will need this as per https://tools.ietf.org/html/rfc6455#section-5.5
                    if (!$isControlFrame && $dataMsgBytesRecd >= $nextEmit) {
                        if ($isMasked) {
                            $payload ^= str_repeat($maskingKey, ($frameBytesRecd + 3) >> 2);
                            // Shift the mask so that the next data where the mask is used on has correct offset.
                            $maskingKey = substr($maskingKey . $maskingKey, $frameBytesRecd % 4, 4);
                        }

                        if ($dataArr) {
                            $dataArr[] = $payload;
                            $payload = implode($dataArr);
                            $dataArr = [];
                        }

                        if ($doUtf8Validation) {
                            $string = $payload;
                            /* @TODO: check how many bits are set to 1 instead of multiple (slow) preg_match()es and substr()s */
                            for ($i = 0; !preg_match('//u', $payload) && $i < 8; $i++) {
                                $payload = substr($payload, 0, -1);
                            }
                            if ($i == 8) {
                                $code = Code::INCONSISTENT_FRAME_DATA_TYPE;
                                $errorMsg = 'Invalid TEXT data; UTF-8 required';
                                break 2;
                            }

                            $emitCallback([self::DATA, $payload, false], $callbackData);
                            $payload = $i > 0 ? substr($string, -$i) : '';
                        } else {
                            $emitCallback([self::DATA, $payload, false], $callbackData);
                            $payload = '';
                        }

                        $frameLength -= $frameBytesRecd;
                        $nextEmit = $dataMsgBytesRecd + $emitThreshold;
                        $frameBytesRecd = 0;
                    }

                    $buffer = yield $frames;
                    $bufferSize = \strlen($buffer);
                    $frames = 0;

                    if ($bufferSize + $frameBytesRecd >= $frameLength) {
                        $dataLen = $frameLength - $frameBytesRecd;
                    } else {
                        $dataLen = $bufferSize;
                    }

                    if (!$isControlFrame) {
                        $dataMsgBytesRecd += $dataLen;
                    }

                    $payload .= substr($buffer, 0, $dataLen);
                    $frameBytesRecd += $dataLen;
                } while ($frameBytesRecd != $frameLength);

                $offset = $dataLen;
                $bufferSize -= $dataLen;
            }

            if ($isMasked) {
                // This is memory hungry but it's ~70x faster than iterating byte-by-byte
                // over the masked string. Deal with it; manual iteration is untenable.
                $payload ^= str_repeat($maskingKey, ($frameLength + 3) >> 2);
            }

            if ($fin || $dataMsgBytesRecd >= $emitThreshold) {
                if ($isControlFrame) {
                    $emit = [self::CONTROL, $payload, $opcode];
                } else {
                    if ($dataArr) {
                        $dataArr[] = $payload;
                        $payload = implode($dataArr);
                        $dataArr = [];
                    }

                    if ($doUtf8Validation) {
                        if ($fin) {
                            $i = preg_match('//u', $payload) ? 0 : 8;
                        } else {
                            $string = $payload;
                            for ($i = 0; !preg_match('//u', $payload) && $i < 8; $i++) {
                                $payload = substr($payload, 0, -1);
                            }
                            if ($i > 0) {
                                $dataArr[] = substr($string, -$i);
                            }
                        }
                        if ($i == 8) {
                            $code = Code::INCONSISTENT_FRAME_DATA_TYPE;
                            $errorMsg = 'Invalid TEXT data; UTF-8 required';
                            break;
                        }
                    }

                    $emit = [self::DATA, $payload, $fin];

                    if ($fin) {
                        $dataMsgBytesRecd = 0;
                    }
                    $nextEmit = $dataMsgBytesRecd + $emitThreshold;
                }

                $emitCallback($emit, $callbackData);
            } else {
                $dataArr[] = $payload;
            }

            $frames++;
        }

        // An error occurred...
        // stop parsing here ...
        $emitCallback([self::ERROR, $errorMsg, $code], $callbackData);
        yield $frames;
        while (1) {
            yield 0;
        }
    }

    public function monitor(): array {
        return [
            "handler" => [get_class($this->application), $this->application instanceof Monitor ? $this->application->monitor() : null],
            "clients" => array_map(function ($client) { return $this->getInfo($client->id); }, $this->clients),
        ];
    }
}