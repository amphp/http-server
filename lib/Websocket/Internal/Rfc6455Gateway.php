<?php

namespace Aerys\Websocket\Internal;

use Aerys\ClientException;
use Aerys\Monitor;
use Aerys\NullBody;
use Aerys\Request;
use Aerys\Responder;
use Aerys\Response;
use Aerys\Server;
use Aerys\ServerObserver;
use Aerys\Websocket;
use Aerys\Websocket\Code;
use Amp\ByteStream\IteratorStream;
use Amp\ByteStream\StreamException;
use Amp\CallableMaker;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Emitter;
use Amp\Failure;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\Socket;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;
use const Aerys\HTTP_STATUS;
use function Aerys\makeGenericBody;
use function Amp\call;

class Rfc6455Gateway implements Monitor, Responder, ServerObserver {
    use CallableMaker;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var \Aerys\Websocket */
    private $application;

    /** @var \Aerys\Websocket\Rfc6455Endpoint */
    private $endpoint;

    /** @var int */
    private $state;

    /** @var \Aerys\Websocket\Internal\Rfc6455Client[] */
    private $clients = [];

    /** @var \Aerys\Websocket\Internal\Rfc6455Client[] */
    private $lowCapacityClients = [];

    /** @var \Aerys\Websocket\Internal\Rfc6455Client[] */
    private $highFramesPerSecondClients = [];

    /** @var int[] */
    private $closeTimeouts = [];

    /** @var int[] */
    private $heartbeatTimeouts = [];

    /** @var string */
    private $timeoutWatcher;

    /** @var int */
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

    // private callables that we pass to external code //
    private $reapClient;

    /* Frame control bits */
    const FIN      = 0b1;
    const RSV_NONE = 0b000;
    const OP_CONT  = 0x00;
    const OP_TEXT  = 0x01;
    const OP_BIN   = 0x02;
    const OP_CLOSE = 0x08;
    const OP_PING  = 0x09;
    const OP_PONG  = 0x0A;

    public function __construct(PsrLogger $logger, Websocket $application) {
        $this->logger = $logger;
        $this->application = $application;
        $this->now = time();
        $this->endpoint = new Websocket\Rfc6455Endpoint($this);

        $this->reapClient = $this->callableFromInstanceMethod("reapClient");
    }

    public function setOption(string $option, $value) {
        switch ($option) {
            case "maxBytesPerMinute":
            case "autoFrameSize":
            case "maxFrameSize":
            case "maxFramesPerSecond":
            case "maxMsgSize":
            case "heartbeatPeriod":
            case "closePeriod":
            case "queuedPingLimit":
                if (0 >= $value = filter_var($value, FILTER_VALIDATE_INT)) {
                    throw new \Error("$option must be a positive integer greater than 0");
                }
                break;
            case "validateUtf8":
            case "textOnly":
                if (null === $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) {
                    throw new \Error("$option must be a boolean value");
                }
                break;
            default:
                throw new \Error("Unknown option $option");
        }
        $this->{$option} = $value;
    }

    public function respond(Request $request): Promise {
        return new Coroutine($this->do($request));
    }

    public function do(Request $request): \Generator {
        if ($request->getMethod() !== "GET") {
            $status = HTTP_STATUS["METHOD_NOT_ALLOWED"];
            return new Response\HtmlResponse(makeGenericBody($status), ["Allow" => "GET"], $status);
        }

        if ($request->getProtocolVersion() !== "1.1") {
            $status = HTTP_STATUS["HTTP_VERSION_NOT_SUPPORTED"];
            return new Response\HtmlResponse(makeGenericBody($status), [], $status);
        }

        $body = $request->getBody();
        if (!$body instanceof NullBody) {
            $status = HTTP_STATUS["BAD_REQUEST"];
            return new Response\HtmlResponse(makeGenericBody($status), ["Connection" => "close"], $status);
        }

        $hasUpgradeWebsocket = false;
        foreach ($request->getHeaderArray("Upgrade") as $value) {
            if (strcasecmp($value, "websocket") === 0) {
                $hasUpgradeWebsocket = true;
                break;
            }
        }
        if (empty($hasUpgradeWebsocket)) {
            $status = HTTP_STATUS["UPGRADE_REQUIRED"];
            return new Response\HtmlResponse(makeGenericBody($status), [], $status);
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
            $status = HTTP_STATUS["UPGRADE_REQUIRED"];
            $reason = "Bad Request: \"Connection: Upgrade\" header required";
            return new Response\HtmlResponse(makeGenericBody($status), ["Upgrade" => "websocket"], $status, $reason);
        }

        if (!$acceptKey = $request->getHeader("Sec-Websocket-Key")) {
            $status = HTTP_STATUS["BAD_REQUEST"];
            $reason = "Bad Request: \"Sec-Websocket-Key\" header required";
            return new Response\HtmlResponse(makeGenericBody($status), [], $status, $reason);
        }

        if (!in_array("13", $request->getHeaderArray("Sec-Websocket-Version"))) {
            $status = HTTP_STATUS["BAD_REQUEST"];
            $reason = "Bad Request: Requested Websocket version unavailable";
            return new Response\HtmlResponse(makeGenericBody($status), [], $status, $reason);
        }

        $response = new Websocket\Handshake($acceptKey);
        $onHandshakeResult = yield call([$this->application, "onHandshake"], $request);

        if ($onHandshakeResult instanceof Response) {
            return $response;
        }

        $response->detach($this->reapClient, $onHandshakeResult);
        return $response;
    }

    public function reapClient(Socket $socket, $data = null): Rfc6455Client {
        $client = new Rfc6455Client;
        $client->capacity = $this->maxBytesPerMinute;
        $client->connectedAt = $this->now;
        $client->id = (int) $socket->getResource();
        $client->socket = $socket;

        $client->parser = $this->parser($client, $options = [
            "max_msg_size" => $this->maxMsgSize,
            "max_frame_size" => $this->maxFrameSize,
            "validate_utf8" => $this->validateUtf8,
            "text_only" => $this->textOnly,
        ]);

        $this->clients[$client->id] = $client;
        $this->heartbeatTimeouts[$client->id] = $this->now + $this->heartbeatPeriod;

        Promise\rethrow(new Coroutine($this->tryAppOnOpen($client->id, $data)));

        Promise\rethrow(new Coroutine($this->read($client)));

        return $client;
    }

    private function read(Rfc6455Client $client): \Generator {
        while (($chunk = yield $client->socket->read()) !== null) {
            if ($client->parser === null) {
                return;
            }

            $client->lastReadAt = $this->now;
            $client->bytesRead += \strlen($chunk);
            $client->capacity -= \strlen($chunk);

            $frames = $client->parser->send($chunk);
            $client->framesRead += $frames;
            $client->framesLastSecond += $frames;

            if ($client->capacity < $this->maxBytesPerMinute / 2) {
                $this->lowCapacityClients[$client->id] = $client;
                if ($client->capacity < 0) {
                    $client->rateDeferred = new Deferred;
                    yield $client->rateDeferred->promise();
                }
            }

            if ($client->framesLastSecond > $this->maxFramesPerSecond / 2) {
                $this->highFramesPerSecondClients[$client->id] = $client;
                if ($client->framesLastSecond > $this->maxFramesPerSecond) {
                    $client->rateDeferred = new Deferred;
                    yield $client->rateDeferred->promise();
                }
            }
        }

        if (!$client->closedAt) {
            $client->closedAt = $this->now;
            $client->closeCode = Code::ABNORMAL_CLOSE;
            $client->closeReason = "Client closed underlying TCP connection";
            Promise\rethrow(new Coroutine($this->tryAppOnClose($client->id, $client->closeCode, $client->closeReason)));
            $client->socket->close();
        }

        $this->unloadClient($client);
    }

    private function onAppError(int $clientId, \Throwable $e): \Generator {
        $this->logger->error((string) $e);
        $code = Code::UNEXPECTED_SERVER_ERROR;
        $reason = "Internal server error, aborting";
        if (isset($this->clients[$clientId])) { // might have been already unloaded + closed
            yield from $this->doClose($this->clients[$clientId], $code, $reason);
        }
    }

    private function doClose(Rfc6455Client $client, int $code, string $reason): \Generator {
        // Only proceed if we haven't already begun the close handshake elsewhere
        if ($client->closedAt) {
            return 0;
        }

        $client->closeCode = $code;
        $client->closeReason = $reason;

        $bytes = 0;

        try {
            $this->closeTimeouts[$client->id] = $this->now + $this->closePeriod;
            $promise = $this->sendCloseFrame($client, $code, $reason);
            yield from $this->tryAppOnClose($client->id, $code, $reason);
            $bytes = yield $promise;
        } catch (ClientException $e) {
            // Ignore client failures.
        } catch (StreamException $e) {
            // Ignore stream failures, closing anyway.
        } finally {
            $client->socket->close();
            // Do not unload client here, will be unloaded later.
        }

        return $bytes;
    }

    private function sendCloseFrame(Rfc6455Client $client, int $code, string $msg): Promise {
        \assert($code !== Code::NONE || $msg == "");
        $promise = $this->write($client, $code !== Code::NONE ? pack('n', $code) . $msg : "", self::OP_CLOSE);
        $client->closedAt = $this->now;
        return $promise;
    }

    private function tryAppOnOpen(int $clientId, $onHandshakeResult): \Generator {
        try {
            yield call([$this->application, "onOpen"], $clientId, $onHandshakeResult);
        } catch (\Throwable $e) {
            yield from $this->onAppError($clientId, $e);
        }
    }

    private function tryAppOnData(Rfc6455Client $client, Websocket\Message $msg): \Generator {
        try {
            yield call([$this->application, "onData"], $client->id, $msg);
        } catch (\Throwable $e) {
            yield from $this->onAppError($client->id, $e);
        }
    }

    private function tryAppOnClose(int $clientId, int $code, string $reason): \Generator {
        try {
            yield call([$this->application, "onClose"], $clientId, $code, $reason);
        } catch (\Throwable $e) {
            yield from $this->onAppError($clientId, $e);
        }
    }

    private function unloadClient(Rfc6455Client $client) {
        $client->parser = null;

        $id = $client->id;
        if ($client->rateDeferred) { // otherwise we may pile up circular references in read()
            $client->rateDeferred->resolve();
            unset($client->rateDeferred, $this->highFramesPerSecondClients[$id], $this->lowCapacityClients[$id]);
        }
        unset($this->clients[$id], $this->heartbeatTimeouts[$id], $this->closeTimeouts[$id]);

        // fail not yet terminated message streams; they *must not* be failed before client is removed
        if ($client->msgEmitter) {
            $emitter = $client->msgEmitter;
            $client->msgEmitter = null;
            $emitter->fail(new ClientException);
        }
    }

    public function onParsedControlFrame(Rfc6455Client $client, int $opcode, string $data) {
        // something went that wrong that we had to close... if parser has anything left, we don't care!
        if ($client->closedAt) {
            return;
        }

        switch ($opcode) {
            case self::OP_CLOSE:
                if ($client->closedAt) {
                    $this->unloadClient($client);
                } else {
                    $length = \strlen($data);
                    if ($length === 0) {
                        $code = Code::NONE;
                        $reason = '';
                    } elseif ($length < 2) {
                        $code = Code::PROTOCOL_ERROR;
                        $reason = 'Close code must be two bytes';
                    } else {
                        $code = current(unpack('n', substr($data, 0, 2)));
                        $reason = substr($data, 2);

                        if ($code < 1000 || $code > 1015) {
                            $code = Code::PROTOCOL_ERROR;
                            $reason = 'Invalid close code';
                        } elseif ($this->validateUtf8 && !\preg_match('//u', $reason)) {
                            $code = Code::INCONSISTENT_FRAME_DATA_TYPE;
                            $reason = 'Close reason must be valid UTF-8';
                        }
                    }

                    Promise\rethrow(new Coroutine($this->doClose($client, $code, $reason)));
                }
                break;

            case self::OP_PING:
                $this->write($client, $data, self::OP_PONG);
                break;

            case self::OP_PONG:
                // We need a min() here, else someone might just send a pong frame with a very high pong count and leave TCP connection in open state... Then we'd accumulate connections which never are cleaned up...
                $client->pongCount = min($client->pingCount, $data);
                break;
        }
    }

    public function onParsedData(Rfc6455Client $client, int $opcode, string $data, bool $terminated) {
        // something went that wrong that we had to close... if parser has anything left, we don't care!
        if ($client->closedAt) {
            return;
        }

        $client->lastDataReadAt = $this->now;

        if (!$client->msgEmitter) {
            if ($opcode === self::OP_CONT) {
                $this->onParsedError(
                    $client,
                    Code::PROTOCOL_ERROR,
                    'Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY'
                );
                return;
            }

            $client->msgEmitter = new Emitter;
            $msg = new Websocket\Message(new IteratorStream($client->msgEmitter->iterate()), $opcode === self::OP_BIN);

            Promise\rethrow(new Coroutine($this->tryAppOnData($client, $msg)));

            // Something went wrong and the client has been closed and emitter failed.
            if (!$client->msgEmitter) {
                return;
            }
        } elseif ($opcode !== self::OP_CONT) {
            $this->onParsedError(
                $client,
                Code::PROTOCOL_ERROR,
                'Illegal data type opcode after unfinished previous data type frame; opcode MUST be CONTINUATION'
            );
            return;
        }

        $client->msgEmitter->emit($data);

        if ($terminated) {
            $client->msgEmitter->complete();
            $client->msgEmitter = null;
            ++$client->messagesRead;
        }
    }

    public function onParsedError(Rfc6455Client $client, int $code, string $msg) {
        // something went that wrong that we had to close... if parser has anything left, we don't care!
        if ($client->closedAt) {
            return;
        }

        Promise\rethrow(new Coroutine($this->doClose($client, $code, $msg)));
    }

    private function compile(Rfc6455Client $client, string $msg, int $opcode, bool $fin): string {
        $rsv = 0b000; // @TODO Add filter mechanism based on $client (e.g. gzip)

        $len = \strlen($msg);
        $w = \chr(($fin << 7) | ($rsv << 4) | $opcode);

        if ($len > 0xFFFF) {
            $w .= "\x7F" . \pack('J', $len);
        } elseif ($len > 0x7D) {
            $w .= "\x7E" . \pack('n', $len);
        } else {
            $w .= \chr($len);
        }

        return $w . $msg;
    }

    private function write(Rfc6455Client $client, string $msg, int $opcode, bool $fin = true): Promise {
        if ($client->closedAt) {
            return new Failure(new ClientException);
        }

        $frame = $this->compile($client, $msg, $opcode, $fin);

        ++$client->framesSent;
        $client->bytesSent += \strlen($frame);
        $client->lastSentAt = $this->now;

        return $client->socket->write($frame);
    }

    public function send(string $data, bool $binary, int $clientId): Promise {
        if (!isset($this->clients[$clientId])) {
            return new Success;
        }

        $client = $this->clients[$clientId];
        ++$client->messagesSent;
        $opcode = $binary ? self::OP_BIN : self::OP_TEXT;
        assert($binary || preg_match("//u", $data), "non-binary data needs to be UTF-8 compatible");

        return $client->lastWrite = new Coroutine($this->doSend($client, $data, $opcode));
    }

    private function doSend(Rfc6455Client $client, string $data, int $opcode): \Generator {
        if ($client->lastWrite) {
            yield $client->lastWrite;
        }

        try {
            $bytes = 0;

            if (\strlen($data) > $this->autoFrameSize) {
                $len = \strlen($data);
                $slices = \ceil($len / $this->autoFrameSize);
                $chunks = \str_split($data, \ceil($len / $slices));
                $final = \array_pop($chunks);
                foreach ($chunks as $chunk) {
                    $bytes += yield $this->write($client, $chunk, $opcode, false);
                    $opcode = self::OP_CONT;
                }
                $bytes += yield $this->write($client, $final, $opcode, true);
            } else {
                $bytes = yield $this->write($client, $data, $opcode);
            }
        } catch (\Throwable $exception) {
            $this->close($client->id);
            $client->lastWrite = null; // prevent storing a cyclic reference
            throw $exception;
        }

        return $bytes;
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
        return Promise\all($promises);
    }

    public function multicast(string $data, bool $binary, array $clientIds): Promise {
        $promises = [];
        foreach ($clientIds as $id) {
            $promises[] = $this->send($data, $binary, $id);
        }
        return Promise\all($promises);
    }

    public function close(int $clientId, int $code = Code::NORMAL_CLOSE, string $reason = "") {
        if (isset($this->clients[$clientId])) {
            Promise\rethrow(new Coroutine($this->doClose($this->clients[$clientId], $code, $reason)));
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
            'close_code'    => $client->closeCode,
            'close_reason'  => $client->closeReason,
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
                return call([$this->application, "onStart"], $this->endpoint);

            case Server::STARTED:
                $this->timeoutWatcher = Loop::repeat(1000, $this->callableFromInstanceMethod("timeout"));
                break;

            case Server::STOPPING:
                Loop::cancel($this->timeoutWatcher);

                return call(function () {
                    try {
                        yield call([$this->application, "onStop"]);
                    } finally {
                        $code = Code::GOING_AWAY;
                        $reason = "Server shutting down!";

                        $promises = [];
                        foreach ($this->clients as $client) {
                            $promises[] = new Coroutine($this->doClose($client, $code, $reason));
                        }
                    }

                    return yield $promises;
                });

            case Server::STOPPED:
                $promises = [];

                // we are not going to wait for a proper self::OP_CLOSE answer (because else we'd need to timeout for 3 seconds, not worth it), but we will ensure to at least *have written* it
                foreach ($this->clients as $client) {
                    // only if we couldn't successfully send it in STOPPING
                    $code = Code::GOING_AWAY;
                    $reason = "Server shutting down!";

                    $promises[] = new Coroutine($this->doClose($client, $code, $reason));
                }

                return Promise\all($promises);
        }

        return new Success;
    }

    private function sendHeartbeatPing(Rfc6455Client $client) {
        if ($client->pingCount - $client->pongCount > $this->queuedPingLimit) {
            $code = Code::POLICY_VIOLATION;
            $reason = 'Exceeded unanswered PING limit';
            Promise\rethrow(new Coroutine($this->doClose($client, $code, $reason)));
        } else {
            $this->write($client, (string) $client->pingCount++, self::OP_PING);
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
            if ($client->capacity > 0 && !isset($this->highFramesPerSecondClients[$id]) && !$client->closedAt && $client->rateDeferred) {
                $client->rateDeferred->resolve();
            }
        }

        foreach ($this->highFramesPerSecondClients as $id => $client) {
            $client->framesLastSecond -= $this->maxFramesPerSecond;
            if ($client->framesLastSecond < $this->maxFramesPerSecond / 2) {
                unset($this->highFramesPerSecondClients[$id]);
                if ($client->capacity > 0 && !$client->closedAt && $client->rateDeferred) {
                    $client->rateDeferred->resolve();
                }
            }
        }
    }

    /**
     * A stateful generator websocket frame parser.
     *
     * @param \Aerys\Websocket\Internal\Rfc6455Client $client Client associated with event emissions.
     * @param array $options Optional parser settings
     * @return \Generator
     */
    public function parser(Rfc6455Client $client, array $options = []): \Generator {
        $maxFrameSize = $options["max_frame_size"] ?? PHP_INT_MAX;
        $maxMsgSize = $options["max_msg_size"] ?? PHP_INT_MAX;
        $textOnly = $options["text_only"] ?? false;
        $doUtf8Validation = $validateUtf8 = $options["validate_utf8"] ?? false;

        $dataMsgBytesRecd = 0;
        $savedBuffer = '';

        $buffer = yield;
        $offset = 0;
        $bufferSize = \strlen($buffer);
        $frames = 0;

        while (1) {
            if ($bufferSize < 2) {
                $buffer = \substr($buffer, $offset);
                $offset = 0;
                do {
                    $buffer .= yield $frames;
                    $bufferSize = \strlen($buffer);
                    $frames = 0;
                } while ($bufferSize < 2);
            }

            $firstByte = \ord($buffer[$offset]);
            $secondByte = \ord($buffer[$offset + 1]);

            $offset += 2;
            $bufferSize -= 2;

            $fin = (bool) ($firstByte & 0b10000000);
            $rsv = ($firstByte & 0b01110000) >> 4; // unused (let's assume the bits are all zero)
            $opcode = $firstByte & 0b00001111;
            $isMasked = (bool) ($secondByte & 0b10000000);
            $maskingKey = null;
            $frameLength = $secondByte & 0b01111111;

            if ($rsv !== 0) {
                $this->onParsedError($client, Code::PROTOCOL_ERROR, 'RSV must be 0 if no extensions are negotiated');
                return;
            }

            if ($opcode >= 3 && $opcode <= 7) {
                $this->onParsedError($client, Code::PROTOCOL_ERROR, 'Use of reserved non-control frame opcode');
                return;
            }

            if ($opcode >= 11 && $opcode <= 15) {
                $this->onParsedError($client, Code::PROTOCOL_ERROR, 'Use of reserved control frame opcode');
                return;
            }

            $isControlFrame = $opcode >= 0x08;
            if ($validateUtf8 && $opcode !== self::OP_CONT && !$isControlFrame) {
                $doUtf8Validation = $opcode === self::OP_TEXT;
            }

            if ($frameLength === 0x7E) {
                if ($bufferSize < 2) {
                    $buffer = \substr($buffer, $offset);
                    $offset = 0;
                    do {
                        $buffer .= yield $frames;
                        $bufferSize = \strlen($buffer);
                        $frames = 0;
                    } while ($bufferSize < 2);
                }

                $frameLength = \unpack('n', $buffer[$offset] . $buffer[$offset + 1])[1];
                $offset += 2;
                $bufferSize -= 2;
            } elseif ($frameLength === 0x7F) {
                if ($bufferSize < 8) {
                    $buffer = \substr($buffer, $offset);
                    $offset = 0;
                    do {
                        $buffer .= yield $frames;
                        $bufferSize = \strlen($buffer);
                        $frames = 0;
                    } while ($bufferSize < 8);
                }

                $lengthLong32Pair = \unpack('N2', \substr($buffer, $offset, 8));
                $offset += 8;
                $bufferSize -= 8;

                if (PHP_INT_MAX === 0x7fffffff) {
                    if ($lengthLong32Pair[1] !== 0 || $lengthLong32Pair[2] < 0) {
                        $this->onParsedError(
                            $client,
                            Code::MESSAGE_TOO_LARGE,
                            'Received payload exceeds maximum allowable size'
                        );
                        return;
                    }
                    $frameLength = $lengthLong32Pair[2];
                } else {
                    $frameLength = ($lengthLong32Pair[1] << 32) | $lengthLong32Pair[2];
                    if ($frameLength < 0) {
                        $this->onParsedError(
                            $client,
                            Code::PROTOCOL_ERROR,
                            'Most significant bit of 64-bit length field set'
                        );
                        return;
                    }
                }
            }

            if ($frameLength > 0 && !$isMasked) {
                $this->onParsedError(
                    $client,
                    Code::PROTOCOL_ERROR,
                    'Payload mask required'
                );
                return;
            }

            if ($isControlFrame) {
                if (!$fin) {
                    $this->onParsedError(
                        $client,
                        Code::PROTOCOL_ERROR,
                        'Illegal control frame fragmentation'
                    );
                    return;
                }

                if ($frameLength > 125) {
                    $this->onParsedError(
                        $client,
                        Code::PROTOCOL_ERROR,
                        'Control frame payload must be of maximum 125 bytes or less'
                    );
                    return;
                }
            }

            if ($maxFrameSize && $frameLength > $maxFrameSize) {
                $this->onParsedError(
                    $client,
                    Code::MESSAGE_TOO_LARGE,
                    'Received payload exceeds maximum allowable size'
                );
                return;
            }

            if ($maxMsgSize && ($frameLength + $dataMsgBytesRecd) > $maxMsgSize) {
                $this->onParsedError(
                    $client,
                    Code::MESSAGE_TOO_LARGE,
                    'Received payload exceeds maximum allowable size'
                );
                return;
            }

            if ($textOnly && $opcode === 0x02) {
                $this->onParsedError(
                    $client,
                    Code::UNACCEPTABLE_TYPE,
                    'BINARY opcodes (0x02) not accepted'
                );
                return;
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

            while ($bufferSize < $frameLength) {
                $chunk = yield $frames;
                $buffer .= $chunk;
                $bufferSize += \strlen($chunk);
                $frames = 0;
            }

            if (!$isControlFrame) {
                $dataMsgBytesRecd += $frameLength;
            }

            $payload = \substr($buffer, $offset, $frameLength);
            $offset += $frameLength;
            $bufferSize -= $frameLength;

            if ($isMasked) {
                // This is memory hungry but it's ~70x faster than iterating byte-by-byte
                // over the masked string. Deal with it; manual iteration is untenable.
                $payload ^= str_repeat($maskingKey, ($frameLength + 3) >> 2);
            }

            if ($isControlFrame) {
                $this->onParsedControlFrame($client, $opcode, $payload);
            } else {
                if ($savedBuffer !== '') {
                    $payload = $savedBuffer . $payload;
                    $savedBuffer = '';
                }

                if ($doUtf8Validation) {
                    if ($fin) {
                        $i = \preg_match('//u', $payload) ? 0 : 8;
                    } else {
                        $string = $payload;
                        for ($i = 0; !\preg_match('//u', $payload) && $i < 8; $i++) {
                            $payload = \substr($payload, 0, -1);
                        }
                        if ($i > 0) {
                            $savedBuffer = \substr($string, -$i);
                        }
                    }
                    if ($i === 8) {
                        $this->onParsedError(
                            $client,
                            Code::INCONSISTENT_FRAME_DATA_TYPE,
                            'Invalid TEXT data; UTF-8 required'
                        );
                        return;
                    }
                }

                if ($fin) {
                    $dataMsgBytesRecd = 0;
                }

                $this->onParsedData($client, $opcode, $payload, $fin);
            }

            $frames++;
        }
    }

    public function monitor(): array {
        return [
            "handler" => [get_class($this->application), $this->application instanceof Monitor ? $this->application->monitor() : null],
            "clients" => array_map(function ($client) { return $this->getInfo($client->id); }, $this->clients),
        ];
    }
}
