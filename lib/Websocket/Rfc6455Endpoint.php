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

        if (empty($request->headers["SEC-WEBSOCKET-KEY"])) {
            $response->setStatus(HTTP_STATUS["BAD_REQUEST"]);
            $response->setReason("Bad Request: \"Sec-Broker-Key\" header required");
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
            return;

        }

        if (empty($request->headers["SEC-WEBSOCKET-VERSION"])) {
            $response->setStatus(HTTP_STATUS["BAD_REQUEST"]);
            $response->setReason("Bad Request: \"Sec-WebSocket-Version\" header required");
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
            return;
        }

        $version = null;
        $requestedVersions = explode(',', $request->headers["SEC-WEBSOCKET-VERSION"]);
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
        return $this->import($request, $response);
    }

    private function import(Request $request, Response $response): \Generator {
        $client = new Rfc6455Client;
        $client->connectedAt = $request->time;

        $upgradePromisor = new Deferred;
        $acceptKey = $request->headers["SEC-WEBSOCKET-KEY"];
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
        $client->parser = $this->parser([$this, "onParse"], $options = [
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
        $this->heartbeatTimeouts[$client->id] = $this->now + $this->heartbeatPeriod;

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
        error_log((string) $e);
        $code = CODES["UNEXPECTED_SERVER_ERROR"];
        $reason = "Internal server error, aborting";
        yield from $this->doClose($this->clients[$clientId], $code, $reason);
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
        // Don't unload the client here, it will be unloaded upon timeout
    }

    private function sendCloseFrame(Rfc6455Client $client, $code, $msg) {
        $client->closedAt = $this->now;
        return $this->compile($client, pack('n', $code) . $msg, FRAME["OP_CLOSE"]);
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
            case FRAME["OP_CLOSE"]:
                if (!$client->closedAt) {
                    if (\strlen($data) < 2) {
                        return; // invalid close reason
                    }
                    $code = current(unpack('S', substr($data, 0, 2)));
                    $reason = substr($data, 2);

                    @stream_socket_shutdown($client->socket, STREAM_SHUT_RD);
                    $this->reactor->cancel($client->readWatcher);
                    $client->readWatcher = null;
                    resolve($this->doClose($client, $code, $reason));
                }
                break;

            case FRAME["OP_PING"]:
                $this->compile($client, $data, FRAME["OP_PONG"]);
                break;

            case FRAME["OP_PONG"]:
                // We need a min() here, else someone might just send a pong frame with a very high pong count and leave TCP connection in open state... Then we'd accumulate connections which never are cleaned up...
                $client->pongCount = min($client->pingCount, $data);
                break;
        }
    }
    
    private function onParsedData(Rfc6455Client $client, array $parseResult) {
        if ($client->closedAt) {
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
        } catch (\BaseException $e) {
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
            if ($client->closedAt || $code == CODES["PROTOCOL_ERROR"]) {
                @stream_socket_shutdown($client->socket, STREAM_SHUT_RD);
                $this->reactor->cancel($client->readWatcher);
                $client->readWatcher = null;
            }

            if (!$client->closedAt) {
                resolve($this->doClose($client, $code, $msg));
            }
        }
    }

    public function onReadable($reactor, $watcherId, $socket, $client) {
        $data = @fread($socket, 8192);
        if ($data != "") {
            $client->lastReadAt = $this->now;
            $client->bytesRead += \strlen($data);
            $client->framesRead += $client->parser->send($data);
        } elseif (!is_resource($socket) || @feof($socket)) {
            if (!$client->closedAt) {
                $client->closedAt = $this->now;
                resolve($this->tryAppOnClose($client->id, CODES["ABNORMAL_CLOSE"], "Client closed underlying TCP connection"));
            } else {
                unset($this->closeTimeouts[$client->id]);
            }

            $this->unloadClient($client);
        }
    }

    public function onWritable($reactor, $watcherId, $socket, Rfc6455Client $client) {
retry:
        $bytes = @fwrite($socket, $client->writeBuffer);
        $client->bytesSent += $bytes;
        if ($bytes != \strlen($client->writeBuffer)) {
            $client->writeBuffer = substr($client->writeBuffer, $bytes);
        } elseif ($bytes == 0 && $client->closedAt && (!is_resource($socket) || @feof($socket))) {
            // usually read watcher cares about aborted TCP connections, but when $client->closedAt is true, it might be the case that read watcher is already cancelled and we need to ensure that our writing promise is fulfilled in unloadClient() with a failure
            unset($this->closeTimeouts[$client->id]);
            $this->unloadClient($client);
        } else {
            $client->framesSent++;
            $client->writeDeferred->succeed();
            if ($client->writeControlQueue) {
                $client->writeBuffer = array_shift($client->writeControlQueue);
                $client->lastSentAt = $this->now;
                $client->writeDeferred = array_shift($client->writeDeferredControlQueue);
                goto retry;
            } elseif ($client->closedAt) {
                @stream_socket_shutdown($socket, STREAM_SHUT_WR);
                $reactor->cancel($watcherId);
                $client->writeWatcher = null;
            } elseif ($client->writeDataQueue) {
                $client->writeBuffer = array_shift($client->writeDataQueue);
                $client->lastDataSentAt = $this->now;
                $client->lastSentAt = $this->now;
                $client->writeDeferred = array_shift($client->writeDeferredDataQueue)->succeed();
                goto retry;
            } else {
                $client->writeBuffer = "";
                $reactor->disable($watcherId);
            }
        }
    }

    private function compile(Rfc6455Client $client, string $msg, int $opcode = FRAME["OP_BIN"], bool $fin = true): Promise {
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
        $len = strlen($msg);

        $w = chr(($frameInfo["fin"] << 7) | ($frameInfo["rsv"] << 4) | $frameInfo["opcode"]);

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
                $deferred = $client->writeDeferredDataQueue[] = new Deferred;
            } else {
                $client->writeDataQueue[] = $w;
                $deferred =  $client->writeDeferredControlQueue[] = new Deferred;
            }
        } else {
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

    public function send(int $clientId, string $data): Promise {
        if ($client = $this->clients[$clientId] ?? null) {
            $client->messagesSent++;

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
            return $this->compile($client, $data, $opcode);
        }

        return new Success;
    }

    public function broadcast(string $data, array $clientIds = null): Promise {
        if ($clientIds === null) {
            $clientIds = array_keys($this->clients);
        }

        $promises = [];
        foreach ($clientIds as $clientId) {
            $promises[] = $this->send($data, $clientId);
        }
        return all($promises);
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

                $this->reactor->cancel($this->timeoutWatcher);
                $this->timeoutWatcher = null;
                break;

            case Server::STOPPED:
                $result = $this->application->onStop();
                if ($result instanceof \Generator) {
                    resolve($result);
                }

                // we are not going to wait for a proper OP_CLOSE answer (because else we'd need to timeout for 3 seconds, not worth it), but we will ensure to at least *have written* it
                $promises = [];
                foreach ($this->clients as $client) {
                    $promise = end($client->writeDeferredControlQueue)->promise();
                    if ($promise) {
                        $promises[] = $promise;
                    }
                }
                return any($promises)->when(function () {
                    foreach ($this->clients as $client) {
                        $this->unloadClient($client);
                    }
                });
        }

        return new Success;
    }

    private function sendHeartbeatPing(Rfc6455Client $client) {
        if ($client->pingCount - $client->pongCount > $this->queuedPingLimit) {
            $code = CODES["POLICY_VIOLATION"];
            $reason = 'Exceeded unanswered PING limit';
            $this->doClose($client, $code, $reason);
        } else {
            $this->compile($client, $client->pingCount++, FRAME["OP_PING"]);
        }
    }

    private function timeout() {
        $this->now = $now = time();

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
        foreach ($this->closeTimeouts as $clientId => $expiryTime) {
            if ($expiryTime < $now) {
                $this->unloadClient($this->clients[$clientId]);
                unset($this->closeTimeouts[$clientId]);
            } else {
                break;
            }
        }
    }

    public function parser(callable $emitCallback, $options = []): \Generator {
        $callbackData = $options["cb_data"] ?? null;
        $emitThreshold = $options["threshold"] ?? 32768;
        $maxFrameSize = $options["max_frame_size"] ?? PHP_INT_MAX;
        $maxMsgSize = $options["max_msg_size"] ?? PHP_INT_MAX;
        $textOnly = $options["text_only"] ?? false;
        $validateUtf8 = $options["validate_utf8"] ?? false;

        $dataMsgBytesRecd = 0;
        $dataArr = [];

        $buffer = yield;
        $bufferSize = \strlen($buffer);
        $frames = 0;

        while (1) {
            $frameBytesRecd = 0;
            $isControlFrame = null;
            $payloadReference = '';

            while ($bufferSize < 2) {
                $buffer .= yield $frames;
                $bufferSize = \strlen($buffer);
                $frames = 0;
            }

            $firstByte = ord($buffer[0]);
            $secondByte = ord($buffer[1]);

            $buffer = substr($buffer, 2);
            $bufferSize -= 2;

            $fin = (bool)($firstByte & 0b10000000);
            // $rsv = ($firstByte & 0b01110000) >> 4; // unused (let's assume the bits are all zero)
            $opcode = $firstByte & 0b00001111;
            $isMasked = (bool)($secondByte & 0b10000000);
            $maskingKey = null;
            $frameLength = $secondByte & 0b01111111;

            $isControlFrame = ($opcode >= 0x08);

            if ($frameLength === 0x7E) {
                while ($bufferSize < 2) {
                    $buffer .= yield $frames;
                    $bufferSize = \strlen($buffer);
                    $frames = 0;
                }

                $frameLength = current(unpack('n', $buffer[0] . $buffer[1]));
                $buffer = substr($buffer, 2);
                $bufferSize -= 2;
            } elseif ($frameLength === 0x7F) {
                while ($bufferSize < 8) {
                    $buffer .= yield $frames;
                    $bufferSize = \strlen($buffer);
                    $frames = 0;
                }

                $lengthLong32Pair = array_values(unpack('N2', substr($buffer, 0, 8)));
                $buffer = substr($buffer, 8);
                $bufferSize -= 8;

                if (PHP_INT_MAX === 0x7fffffff) {
                    if ($lengthLong32Pair[0] !== 0 || $lengthLong32Pair[1] < 0) {
                        $code = CODES["MESSAGE_TOO_LARGE"];
                        $errorMsg = 'Payload exceeds maximum allowable size';
                        break;
                    }
                    $frameLength = $lengthLong32Pair[1];
                } else {
                    $frameLength = ($lengthLong32Pair[0] << 32) | $lengthLong32Pair[1];
                    if ($frameLength < 0) {
                        $code = CODES["PROTOCOL_ERROR"];
                        $errorMsg = 'Most significant bit of 64-bit length field set';
                        break;
                    }
                }
            }

            if ($isControlFrame && !$fin) {
                $code = CODES["PROTOCOL_ERROR"];
                $errorMsg = 'Illegal control frame fragmentation';
                break;
            } elseif ($isControlFrame && $frameLength > 125) {
                $code = CODES["PROTOCOL_ERROR"];
                $errorMsg = 'Control frame payload must be of maximum 125 bytes or less';
                break;
            } elseif ($maxFrameSize && $frameLength > $maxFrameSize) {
                $code = CODES["MESSAGE_TOO_LARGE"];
                $errorMsg = 'Payload exceeds maximum allowable frame size';
                break;
            } elseif ($maxMsgSize && ($frameLength + $dataMsgBytesRecd) > $maxMsgSize) {
                $code = CODES["MESSAGE_TOO_LARGE"];
                $errorMsg = 'Payload exceeds maximum allowable message size';
                break;
            } elseif ($textOnly && $opcode === 0x02) {
                $code = CODES["UNACCEPTABLE_TYPE"];
                $errorMsg = 'BINARY opcodes (0x02) not accepted';
                break;
            } elseif ($frameLength > 0 && !$isMasked) {
                $code = CODES["PROTOCOL_ERROR"];
                $errorMsg = 'Payload mask required';
                break;
            } elseif (!($opcode || $isControlFrame)) {
                $code = CODES["PROTOCOL_ERROR"];
                $errorMsg = 'Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY';
                break;
            }

            if ($isMasked) {
                while ($bufferSize < 4) {
                    $buffer .= yield $frames;
                    $bufferSize = \strlen($buffer);
                    $frames = 0;
                }

                $maskingKey = substr($buffer, 0, 4);
                $buffer = substr($buffer, 4);
                $bufferSize -= 4;
            }

            while (1) {
                if (($bufferSize + $frameBytesRecd) >= $frameLength) {
                    $dataLen = $frameLength - $frameBytesRecd;
                } else {
                    $dataLen = $bufferSize;
                }

                if ($isControlFrame) {
                    $payloadReference =& $controlPayload;
                } else {
                    $payloadReference =& $dataPayload;
                    $dataMsgBytesRecd += $dataLen;
                }

                $payloadReference .= substr($buffer, 0, $dataLen);
                $frameBytesRecd += $dataLen;

                $buffer = substr($buffer, $dataLen);
                $bufferSize -= $dataLen;

                if ($frameBytesRecd == $frameLength) {
                    break;
                }

                // if we want to validate UTF8, we must *not* send incremental mid-frame updates because the message might be broken in the middle of an utf-8 sequence
                // also, control frames always are <= 125 bytes, so we never will need this as per https://tools.ietf.org/html/rfc6455#section-5.5
                if (!$isControlFrame && !($opcode === self::OP_TEXT && $validateUtf8) && $dataMsgBytesRecd >= $emitThreshold) {
                    if ($isMasked) {
                        $payloadReference ^= str_repeat($maskingKey, ($frameBytesRecd + 3) >> 2);
                        // Shift the mask so that the next data where the mask is used on has correct offset.
                        $maskingKey = substr($maskingKey . $maskingKey, $frameBytesRecd % 4, 4);
                    }

                    $emitCallback([self::DATA, $payloadReference, false], $callbackData);

                    $frameLength -= $frameBytesRecd;
                    $frameBytesRecd = 0;
                    $payloadReference = '';
                }

                $buffer .= yield $frames;
                $bufferSize = \strlen($buffer);
                $frames = 0;
            }

            if ($isMasked) {
                // This is memory hungry but it's ~70x faster than iterating byte-by-byte
                // over the masked string. Deal with it; manual iteration is untenable.
                $payloadReference ^= str_repeat($maskingKey, ($frameLength + 3) >> 2);
            }

            if ($opcode === self::OP_TEXT && $validateUtf8 && !preg_match('//u', $payloadReference)) {
                $code = CODES["INCONSISTENT_FRAME_DATA_TYPE"];
                $errorMsg = 'Invalid TEXT data; UTF-8 required';
                break;
            }

            $frames++;

            if ($fin || $dataMsgBytesRecd >= $emitThreshold) {
                if ($isControlFrame) {
                    $emit = [self::CONTROL, $payloadReference, $opcode];
                } else {
                    if ($dataArr) {
                        $dataArr[] = $payloadReference;
                        $payloadReference = implode($dataArr);
                        $dataArr = [];
                    }

                    $emit = [self::DATA, $payloadReference, $fin];
                    $dataMsgBytesRecd = 0;
                }

                $emitCallback($emit, $callbackData);
            } else {
                $dataArr[] = $payloadReference;
            }
        }

        // An error occurred...
        // stop parsing here ...
        $emitCallback([self::ERROR, $errorMsg, $code], $callbackData);
        yield $frames;
        while (1) {
            yield 0;
        }
    }
}