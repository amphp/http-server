<?php

namespace Amp\Http\Server\Websocket\Internal;

use Amp\ByteStream\IteratorStream;
use Amp\ByteStream\StreamException;
use Amp\CallableMaker;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Emitter;
use Amp\Failure;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Request;
use Amp\Http\Server\Responder;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Server\ServerObserver;
use Amp\Http\Server\Websocket\Application;
use Amp\Http\Server\Websocket\Code;
use Amp\Http\Server\Websocket\Message;
use Amp\Http\Status;
use Amp\Promise;
use Amp\Socket\Socket;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\call;

class Rfc6455Gateway implements Responder, ServerObserver {
    use CallableMaker;

    /** @var PsrLogger */
    private $logger;

    /** @var Application */
    private $application;

    /** @var Rfc6455Endpoint */
    private $endpoint;

    /** @var \Amp\Http\Server\ErrorHandler */
    private $errorHandler;

    /** @var Rfc6455Client[] */
    private $clients = [];

    /** @var Rfc6455Client[] */
    private $lowCapacityClients = [];

    /** @var Rfc6455Client[] */
    private $highFramesPerSecondClients = [];

    /** @var int[] */
    private $closeTimeouts = [];

    /** @var int[] */
    private $heartbeatTimeouts = [];

    /** @var int */
    private $now;

    private $autoFrameSize = (64 << 10) - 9 /* frame overhead */;
    private $maxBytesPerMinute = 8 << 20;
    private $maxFrameSize = 2 << 20;
    private $maxMessageSize = 2 << 20;
    private $heartbeatPeriod = 10;
    private $closePeriod = 3;
    private $validateUtf8 = true;
    private $textOnly = false;
    private $queuedPingLimit = 3;
    private $maxFramesPerSecond = 100; // do not bother with setting it too low, fread(8192) may anyway include up to 2700 frames
    private $compressionEnabled = false;

    // Frame control bits
    const FIN      = 0b1;
    const RSV_NONE = 0b000;
    const OP_CONT  = 0x00;
    const OP_TEXT  = 0x01;
    const OP_BIN   = 0x02;
    const OP_CLOSE = 0x08;
    const OP_PING  = 0x09;
    const OP_PONG  = 0x0A;

    public function __construct(Application $application) {
        $this->application = $application;
        $this->endpoint = new Rfc6455Endpoint($this);
        $this->compressionEnabled = \extension_loaded('zlib');
    }

    public function setOption(string $option, $value) {
        switch ($option) {
            case "maxBytesPerMinute":
            case "autoFrameSize":
            case "maxFrameSize":
            case "maxFramesPerSecond":
            case "maxMessageSize":
            case "heartbeatPeriod":
            case "closePeriod":
            case "queuedPingLimit":
                if (0 >= $value = filter_var($value, FILTER_VALIDATE_INT)) {
                    throw new \Error("$option must be a positive integer greater than 0");
                }
                break;
            case "compressionEnabled":
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

    private function do(Request $request): \Generator {
        /** @var \Amp\Http\Server\Response $response */
        if ($request->getMethod() !== "GET") {
            $response = yield $this->errorHandler->handle(Status::METHOD_NOT_ALLOWED, null, $request);
            $response->setHeader("Allow", "GET");
            return $response;
        }

        if ($request->getProtocolVersion() !== "1.1") {
            $response = yield $this->errorHandler->handle(Status::HTTP_VERSION_NOT_SUPPORTED, null, $request);
            $response->setHeader("Upgrade", "websocket");
            return $response;
        }

        if (null !== yield $request->getBody()->read()) {
            return yield $this->errorHandler->handle(Status::BAD_REQUEST, null, $request);
        }

        $hasUpgradeWebsocket = false;
        foreach ($request->getHeaderArray("Upgrade") as $value) {
            if (strcasecmp($value, "websocket") === 0) {
                $hasUpgradeWebsocket = true;
                break;
            }
        }
        if (!$hasUpgradeWebsocket) {
            return yield $this->errorHandler->handle(Status::UPGRADE_REQUIRED, null, $request);
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

        if (!$hasConnectionUpgrade) {
            $reason = "Bad Request: \"Connection: Upgrade\" header required";
            $response = yield $this->errorHandler->handle(Status::UPGRADE_REQUIRED, $reason, $request);
            $response->setHeader("Upgrade", "websocket");
            return $response;
        }

        if (!$acceptKey = $request->getHeader("Sec-Websocket-Key")) {
            $reason = "Bad Request: \"Sec-Websocket-Key\" header required";
            return yield $this->errorHandler->handle(Status::BAD_REQUEST, $reason, $request);
        }

        if (!\in_array("13", $request->getHeaderArray("Sec-Websocket-Version"), true)) {
            $reason = "Bad Request: Requested Websocket version unavailable";
            $response = yield $this->errorHandler->handle(Status::BAD_REQUEST, $reason, $request);
            $response->setHeader("Sec-Websocket-Version", "13");
            return $response;
        }

        $response = new Rfc6455Handshake($acceptKey);

        if ($this->compressionEnabled) {
            $extensions = (string) $request->getHeader("Sec-Websocket-Extensions");

            $extensions = array_map("trim", explode(',', $extensions));

            foreach ($extensions as $extension) {
                if ($compressionContext = Rfc7692Compression::fromHeader($extension, $headerLine)) {
                    $response->setHeader("Sec-Websocket-Extensions", $headerLine);
                }
                break;
            }
        }

        $response = yield call([$this->application, "onHandshake"], $request, $response);

        if (!$response instanceof Response) {
            throw new \Error(\sprintf(
                "%s::onHandshake() must return or resolve to an instance of %s, %s returned",
                Application::class,
                Response::class,
                \is_object($response) ? "instance of " . \get_class($response) : \gettype($response)
            ));
        }

        if ($response->getStatus() === Status::SWITCHING_PROTOCOLS) {
            $response->upgrade(function (Socket $socket) use ($request) {
                $this->reapClient($socket, $request, $compressionContext ?? null);
            });
        }

        return $response;
    }

    public function reapClient(
        Socket $socket,
        Request $request,
        Rfc7692Compression $compressionContext = null
    ): Rfc6455Client {
        $client = new Rfc6455Client;
        $client->capacity = $this->maxBytesPerMinute;
        $client->connectedAt = $this->now;
        $client->id = (int) $socket->getResource();
        $client->socket = $socket;
        $client->compressionContext = $compressionContext;

        $client->parser = $this->parser($client, $options = [
            "max_msg_size" => $this->maxMessageSize,
            "max_frame_size" => $this->maxFrameSize,
            "validate_utf8" => $this->validateUtf8,
            "text_only" => $this->textOnly,
        ]);

        $this->clients[$client->id] = $client;
        $this->heartbeatTimeouts[$client->id] = $this->now + $this->heartbeatPeriod;

        Promise\rethrow(new Coroutine($this->tryAppOnOpen($client->id, $request)));

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
            yield from $this->tryAppOnClose($client->id, $client->closeCode, $client->closeReason);
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
        \assert($code !== Code::NONE || $msg === "");
        $promise = $this->write($client, $code !== Code::NONE ? pack('n', $code) . $msg : "", self::OP_CLOSE);
        $client->closedAt = $this->now;
        return $promise;
    }

    private function tryAppOnOpen(int $clientId, Request $request): \Generator {
        try {
            yield call([$this->application, "onOpen"], $clientId, $request);
        } catch (\Throwable $e) {
            yield from $this->onAppError($clientId, $e);
        }
    }

    private function tryAppOnData(Rfc6455Client $client, Message $msg): \Generator {
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
                    break;
                }

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

                    if ($code < 1000 // Reserved and unused.
                        || ($code >= 1004 && $code <= 1006) // Should not be sent over wire.
                        || ($code >= 1014 && $code <= 1016) // Should only be sent by server.
                        || ($code >= 1017 && $code <= 1999) // Reserved for future use
                        || ($code >= 2000 && $code <= 2999) // Reserved for WebSocket extensions.
                        || $code >= 5000 // 3000-3999 for libraries, 4000-4999 for applications, >= 5000 invalid.
                    ) {
                        $code = Code::PROTOCOL_ERROR;
                        $reason = 'Invalid close code';
                    } elseif ($this->validateUtf8 && !\preg_match('//u', $reason)) {
                        $code = Code::INCONSISTENT_FRAME_DATA_TYPE;
                        $reason = 'Close reason must be valid UTF-8';
                    }
                }

                Promise\rethrow(new Coroutine($this->doClose($client, $code, $reason)));
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
            $msg = new Message(new IteratorStream($client->msgEmitter->iterate()), $opcode === self::OP_BIN);

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

    private function compile(string $msg, int $opcode, int $rsv, bool $fin): string {
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

    private function write(Rfc6455Client $client, string $msg, int $opcode, int $rsv = 0, bool $fin = true): Promise {
        if ($client->closedAt) {
            return new Failure(new ClientException);
        }

        $frame = $this->compile($msg, $opcode, $rsv, $fin);

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

        \assert($binary || preg_match("//u", $data), "non-binary data needs to be UTF-8 compatible");

        return $client->lastWrite = new Coroutine($this->doSend($client, $data, $opcode));
    }

    private function doSend(Rfc6455Client $client, string $data, int $opcode): \Generator {
        if ($client->lastWrite) {
            yield $client->lastWrite;
        }

        $rsv = 0;

        if ($client->compressionContext && $opcode === self::OP_TEXT) {
            $data = $client->compressionContext->compress($data);
            $rsv |= Rfc7692Compression::RSV;
        }

        try {
            $bytes = 0;

            if (\strlen($data) > $this->autoFrameSize) {
                $len = \strlen($data);
                $slices = \ceil($len / $this->autoFrameSize);
                $chunks = \str_split($data, \ceil($len / $slices));
                $final = \array_pop($chunks);
                foreach ($chunks as $chunk) {
                    $bytes += yield $this->write($client, $chunk, $opcode, $rsv, false);
                    $opcode = self::OP_CONT;
                    $rsv = 0; // RSV must be 0 in continuation frames.
                }
                $bytes += yield $this->write($client, $final, $opcode, $rsv, true);
            } else {
                $bytes = yield $this->write($client, $data, $opcode, $rsv);
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
            $exceptIdLookup = \array_flip($exceptIds);

            if ($exceptIdLookup === null) {
                throw new \Error("Unable to array_flip() the passed IDs");
            }

            foreach ($this->clients as $id => $client) {
                if (isset($exceptIdLookup[$id])) {
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
            'compression_enabled' => (bool) $client->compressionContext,
        ];
    }

    public function getClients(): array {
        return array_keys($this->clients);
    }

    public function onStart(Server $server): Promise {
        $this->logger = $server->getLogger();
        $this->errorHandler = $server->getErrorHandler();

        $server->getTimeReference()->onTimeUpdate($this->callableFromInstanceMethod("timeout"));

        return call([$this->application, "onStart"], $this->endpoint);
    }

    public function onStop(Server $server): Promise {
        return call(function () {
            try {
                yield call([$this->application, "onStop"]);
            } catch (\Throwable $exception) {
                // Exception rethrown below after ensuring all clients are closed.
            }

            $code = Code::GOING_AWAY;
            $reason = "Server shutting down!";

            $promises = [];
            foreach ($this->clients as $client) {
                $promises[] = new Coroutine($this->doClose($client, $code, $reason));
            }

            yield $promises;

            if (isset($exception)) {
                throw $exception;
            }
        });
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

    private function timeout(int $now) {
        $this->now = $now;

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
            if (!$client->closedAt && $client->capacity > 0 && $client->rateDeferred && !isset($this->highFramesPerSecondClients[$id])) {
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
     * @param Rfc6455Client $client Client associated with event emissions.
     * @param array         $options Optional parser settings
     *
     * @return \Generator
     */
    public function parser(Rfc6455Client $client, array $options = []): \Generator {
        $maxFrameSize = $options["max_frame_size"] ?? PHP_INT_MAX;
        $maxMessageSize = $options["max_msg_size"] ?? PHP_INT_MAX;
        $textOnly = $options["text_only"] ?? false;
        $doUtf8Validation = $validateUtf8 = $options["validate_utf8"] ?? false;

        $dataMsgBytesRecd = 0;
        $savedBuffer = '';
        $compressed = false;

        $buffer = yield;
        $offset = 0;
        $bufferSize = \strlen($buffer);
        $frames = 0;

        while (1) {
            $payload = ''; // Free memory from last frame payload.

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

            $final = (bool) ($firstByte & 0b10000000);
            $rsv = ($firstByte & 0b01110000) >> 4;
            $opcode = $firstByte & 0b00001111;
            $isMasked = (bool) ($secondByte & 0b10000000);
            $maskingKey = null;
            $frameLength = $secondByte & 0b01111111;

            if ($opcode >= 3 && $opcode <= 7) {
                $this->onParsedError($client, Code::PROTOCOL_ERROR, 'Use of reserved non-control frame opcode');
                return;
            }

            if ($opcode >= 11 && $opcode <= 15) {
                $this->onParsedError($client, Code::PROTOCOL_ERROR, 'Use of reserved control frame opcode');
                return;
            }

            $isControlFrame = $opcode >= 0x08;

            if ($isControlFrame || $opcode === self::OP_CONT) { // Control and continuation frames
                if ($rsv !== 0) {
                    $this->onParsedError($client, Code::PROTOCOL_ERROR, 'RSV must be 0 for control or continuation frames');
                    return;
                }
            } else { // Text and binary frames
                if ($rsv !== 0 && (!$client->compressionContext || $rsv & ~Rfc7692Compression::RSV)) {
                    $this->onParsedError($client, Code::PROTOCOL_ERROR, 'Invalid RSV value for negotiated extensions');
                    return;
                }

                $doUtf8Validation = $validateUtf8 && $opcode === self::OP_TEXT;
                $compressed = (bool) ($rsv & Rfc7692Compression::RSV);
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
                if (!$final) {
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

            if ($maxMessageSize && ($frameLength + $dataMsgBytesRecd) > $maxMessageSize) {
                $this->onParsedError(
                    $client,
                    Code::MESSAGE_TOO_LARGE,
                    'Received payload exceeds maximum allowable size'
                );
                return;
            }

            if ($textOnly && $opcode === self::OP_BIN) {
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
                $frames++;
                continue;
            }

            $dataMsgBytesRecd += $frameLength;

            if ($savedBuffer !== '') {
                $payload = $savedBuffer . $payload;
                $savedBuffer = '';
            }

            if ($compressed) {
                if (!$final) {
                    $savedBuffer = $payload;
                    $frames++;
                    continue;
                }

                $payload = $client->compressionContext->decompress($payload);

                if ($payload === null) { // Decompression failed.
                    $this->onParsedError(
                        $client,
                        Code::PROTOCOL_ERROR,
                        'Invalid compressed data'
                    );
                    return;
                }
            }

            if ($doUtf8Validation) {
                if ($final) {
                    $valid = \preg_match('//u', $payload);
                } else {
                    for ($i = 0; !($valid = \preg_match('//u', $payload)); $i++) {
                        $savedBuffer .= \substr($payload, -1);
                        $payload = \substr($payload, 0, -1);

                        if ($i === 3) { // Remove a maximum of three bytes
                            break;
                        }
                    }
                }

                if (!$valid) {
                    $this->onParsedError(
                        $client,
                        Code::INCONSISTENT_FRAME_DATA_TYPE,
                        'Invalid TEXT data; UTF-8 required'
                    );
                    return;
                }
            }

            if ($final) {
                $dataMsgBytesRecd = 0;
            }

            $this->onParsedData($client, $opcode, $payload, $final);
            $frames++;
        }
    }
}
