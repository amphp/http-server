<?php

namespace Aerys\Websocket;

use Amp\Reactor;
use Amp\Future;
use Amp\Promise;
use Amp\Success;
use Amp\Failure;
use Amp\Combinator;
use Aerys\Server;
use Aerys\Websocket;
use Aerys\ServerObserver;
use Aerys\ClientGoneException;

class Endpoint implements ServerObserver {
    const HANDSHAKE_ACCEPT_CONCAT = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    const OP_MAX_FRAME_SIZE = 1;
    const OP_MAX_MSG_SIZE = 2;
    const OP_HEARTBEAT_PERIOD = 3;
    const OP_CLOSE_PERIOD = 4;
    const OP_VALIDATE_UTF8 = 5;
    const OP_TEXT_ONLY = 6;
    const OP_AUTO_FRAME_SIZE = 7;
    const OP_QUEUED_PING_LIMIT = 8;

    private $reactor;
    private $websocket;
    private $combinator;
    private $state = Server::STOPPED;

    private $pendingSessions = [];
    private $sessions = [];
    private $closeTimeouts = [];
    private $heartbeatTimeouts = [];
    private $timeoutWatcher;
    private $now;

    private $autoFrameSize = 32768;
    private $maxFrameSize = 2097152;
    private $maxMsgSize = 10485760;
    private $readGranularity = 32768;
    private $heartbeatPeriod = 10;
    private $closePeriod = 3;
    private $validateUtf8 = false;
    private $textOnly = true;
    private $queuedPingLimit = 3;
    private $notifyFrames = false;
    // @TODO add minimum average frame size rate threshold to prevent tiny-frame DoS

    private $errorLogger;

    public function __construct(Reactor $reactor, Websocket $websocket, Combinator $combinator = null) {
        $this->reactor = $reactor;
        $this->websocket = $websocket;
        $this->combinator = $combinator ?: new Combinator($reactor);
        $this->errorLogger = function($error) {
            if (!$error instanceof ClientGoneException) {
                fwrite(STDERR, $error);
            }
        };
    }

    /**
     * Listen for server START/STOPPING notifications
     *
     * Websocket endpoints require notification when the server starts or stops to enable
     * application bootstrapping and graceful shutdown.
     *
     * @param \Aerys\Server $server
     * @param int $event
     * @return \Amp\Promise
     */
    public function onServerUpdate(Server $server, $event) {
        switch ($event) {
            case Server::STARTING:
                $this->state = $event;
                return $this->start();
            case Server::STARTED:
                $this->state = $event;
                return new Success;
            case Server::STOPPING:
                $this->state = $event;
                return $this->stop();
            case Server::STOPPED:
                $this->state = $event;
                break;
        }
    }

    private function start() {
        $this->state = Server::STARTED;
        $this->now = time();
        $this->timeoutWatcher = $this->reactor->repeat([$this, 'timeout'], $msInterval = 1000);
        $this->reactor->disable($this->timeoutWatcher);

        return new Success;
    }

    private function stop() {
        $code = Codes::GOING_AWAY;
        $reason = 'Server is shutting down!';
        $closePromises = [];
        foreach ($this->sessions as $session) {
            $closePromises[] = $this->closeSession($session, $code, $reason);
        }

        $promisor = new Future($this->reactor);
        $sessionClose = $this->combinator->any($closePromises);
        $sessionClose->when(function($e, $r) use ($promisor) {
            $promisor->succeed();
        });

        return $promisor;
    }

    /**
     * Accepts new client sockets exported from the HTTP server
     *
     * @param resource $socket A raw TCP socket stream
     * @param callable $closer A callback that MUST be invoked when the socket disconnects
     * @param array $request   The HTTP request that led to this import operation
     */
    public function import($socket, callable $serverCloseCallback, array $request) {
        $clientId = (int) $socket;
        $session = new Session;
        $session->request = $request;
        $session->serverCloseCallback = $serverCloseCallback;
        $session->connectedAt = $this->now ?: time();
        $session->clientId = $clientId;
        $session->socket = $socket;
        $session->parseState = new ParseState;
        $this->pendingSessions[$clientId] = $session;
        $this->notifyApplicationOnOpen($session);
    }

    private function notifyApplicationOnOpen(Session $session) {
        try {
            $clientId = $session->clientId;
            $request = $session->request;
            $result = $this->websocket->onOpen($clientId, $request);
            if ($result instanceof \Generator) {
                $promise = $this->resolveGenerator($result, $session);
                $promise->when(function($error, $result) use ($session) {
                    // If the websocket handshake wasn't JIT'd as a result of output
                    // to the client we should execute it now.
                    if ($session->handshakeState === Session::HANDSHAKE_NONE) {
                        $this->handshake($session);
                    }
                });
            }
        } catch (\Exception $e) {
            $errorLogger = $this->errorLogger;
            $errorLogger($e);
        }
    }

    private function handshake(Session $session) {
        $status = $session->handshakeHttpStatus;
        $header = $session->handshakeHttpHeader ? implode("\r\n", $session->handshakeHttpHeader) : '';

        if ($status) {
            // It's important to *always* close the connection after failing the
            // handshake because we've already exported the socket from the HTTP
            // server and it's our job to close the socket when we're finished.
            $header = \Aerys\setHeader($header, 'Connection', 'close');
            $reason = $session->handshakeHttpReason ? " {$session->handshakeHttpReason}" : '';
            $rawResponse = "HTTP/1.1 {$status}{$reason}\r\n{$header}\r\n\r\n";
        } else {
            $request = $session->request;
            $concatKeyStr = $request['HTTP_SEC_WEBSOCKET_KEY'] . self::HANDSHAKE_ACCEPT_CONCAT;
            $secWebSocketAccept = base64_encode(sha1($concatKeyStr, true));
            $header = \Aerys\setHeader($header, 'Upgrade', 'websocket');
            $header = \Aerys\setHeader($header, 'Connection', 'upgrade');
            $header = \Aerys\setHeader($header, 'Sec-WebSocket-Accept', $secWebSocketAccept);
            $rawResponse = "HTTP/1.1 101 Switching Protocols\r\n{$header}\r\n\r\n";
        }

        $clientId = $session->clientId;
        $this->sessions[$clientId] = $session;
        unset($this->pendingSessions[$clientId]);

        $session->handshakeState = Session::HANDSHAKE_INIT;
        $session->writeBuffer = $rawResponse;
        $session->writeBufferSize = strlen($rawResponse);
        $session->isWriteWatcherEnabled = true;
        $session->writeWatcher = $this->reactor->onWritable($session->socket, function() use ($session) {
            $this->write($session);
        });
    }

    private function notifyApplicationOnData(Session $session, $payload) {
        try {
            $result = $this->websocket->onData($session->clientId, $payload);
            if ($result instanceof \Generator) {
                $this->resolveGenerator($result, $session);
            }
        } catch (\Exception $e) {
            $errorLogger = $this->errorLogger;
            $errorLogger($e);
        }
    }

    private function notifyApplicationOnClose(Session $session) {
        try {
            $clientId = $session->clientId;
            $code = $session->closeCode;
            $reason = $session->closeReason;
            $result = $this->websocket->onClose($clientId, $code, $reason);
            if ($result instanceof \Generator) {
                $this->resolveGenerator($result, $session);
            }
        } catch (\Exception $e) {
            $errorLogger = $this->errorLogger;
            $errorLogger($e);
        }
    }

    private function resolveGenerator(\Generator $generator, Session $session) {
        $promisor = new Future($this->reactor);
        $promisor->when($this->errorLogger);

        $rs = new ResolverStruct;
        $rs->generator = $generator;
        $rs->promisor = $promisor;
        $rs->session = $session;

        $this->advanceGenerator($rs);

        return $promisor;
    }

    private function advanceGenerator(ResolverStruct $rs, $previousResult = null) {
        try {
            $generator = $rs->generator;
            if ($generator->valid()) {
                $key = $generator->key();
                $current = $generator->current();
                $promiseStruct = $this->promisifyYield($rs, $key, $current);
                $this->reactor->immediately(function() use ($rs, $promiseStruct) {
                    list($promise, $noWait) = $promiseStruct;
                    if ($noWait) {
                        $this->sendToGenerator($rs, $error = null, $result = null);
                    } else {
                        $promise->when(function($error, $result) use ($rs) {
                            $this->sendToGenerator($rs, $error, $result);
                        });
                    }
                });
            } else {
                $rs->promisor->succeed($previousResult);
            }
        } catch (\Exception $error) {
            $rs->promisor->fail($error);
        }
    }

    private function promisifyYield($rs, $key, $current) {
        $session = $rs->session;
        $noWait = false;

        if ($key && $key === (string) $key) {
            goto explicit_key;
        } else {
            goto implicit_key;
        }

        explicit_key: {
            $key = strtolower($key);
            if ($key[0] === Websocket::NOWAIT_PREFIX) {
                $noWait = true;
                $key = substr($key, 1);
            }

            switch ($key) {
                case Websocket::NOWAIT:
                    $noWait = true;
                    goto implicit_key;
                case Websocket::SEND:
                    goto send;
                case Websocket::BROADCAST:
                    goto broadcast;
                case Websocket::INSPECT:
                    goto inspect;
                case Websocket::CLOSE:
                    goto close;
                case Websocket::IMMEDIATELY:
                    goto immediately;
                case Websocket::ONCE:
                    // fallthrough
                case Websocket::REPEAT:
                    goto schedule;
                case Websocket::ON_READABLE:
                    $ioWatchMethod = 'onReadable';
                    goto stream_io_watcher;
                case Websocket::ON_WRITABLE:
                    $ioWatchMethod = 'onWritable';
                    goto stream_io_watcher;
                case Websocket::ENABLE:
                    // fallthrough
                case Websocket::DISABLE:
                    // fallthrough
                case Websocket::CANCEL:
                    goto watcher_control;
                case Websocket::WAIT:
                    goto wait;
                case Websocket::ALL:
                    // fallthrough
                case Websocket::ANY:
                    // fallthrough
                case Websocket::SOME:
                    goto combinator;
                case Websocket::STATUS:
                    goto status;
                case Websocket::REASON:
                    goto reason;
                case Websocket::HEADER:
                    goto header;
                default:
                    $promise = new Failure(new \DomainException(
                        sprintf('Unknown yield key: "%s"', $key)
                    ));
                    goto return_struct;
            }
        }

        implicit_key: {
            if ($current instanceof Promise) {
                $promise = $current;
                goto return_struct;
            } elseif ($current instanceof \Generator) {
                $promise = $this->resolveGenerator($current, $session);
                goto return_struct;
            } elseif (is_array($current)) {
                // An array without an explicit key is assumed to be an "all" combinator
                $key = Websocket::ALL;
                goto combinator;
            } else {
                $promise = new Success($current);
                goto return_struct;
            }
        }

        send: {
            if (empty($session->handshakeState)) {
                $this->handshake($session);
            }
            if (empty($session->handshakeHttpStatus)) {
                $promise = $this->send($current, [$session->clientId]);
            } else {
                $promise = new Failure(new \LogicException(
                    'Invalid send yield: websocket handshake already failed'
                ));
            }
            
            goto return_struct;
        }

        broadcast: {
            if (empty($session->handshakeState)) {
                $this->handshake($session);
            }

            if (is_string($current)) {
                $promise = $this->send($current, []);
            } elseif ($current && isset($current[0], $current[1], $current[2]) && is_array($current)) {
                list($msg, $include, $exclude) = $current;
                $promise = $this->send($msg, $include, $exclude);
            } else {
                $promise = new Failure(new \DomainException(
                    'Invalid broadcast yield: string or [string $msg, array $include, array $exclude] expected'
                ));
            }

            goto return_struct;
        }

        close: {
            if (is_scalar($current)) {
                $clientId = $current;
                $code = Codes::NORMAL_CLOSE;
                $reason = '';
            } elseif ($current && isset($current[0], $current[1], $current[2]) && is_array($current)) {
                list($clientId, $code, $reason) = $current;
            } else {
                $promise = new Failure(new \DomainException(
                    'Invalid close yield: string or [$clientId, $code, $reason] array expected'
                ));
                goto return_struct;
            }

            if (isset($this->sessions[$clientId])) {
                $promise = $this->close($clientId, $code, $reason);
            } elseif ($clientId == $session->clientId) {
                $promise = new Failure(new \DomainException(
                    'Invalid close yield: handshake not yet sent. A "status" command should be ' .
                    'yielded instead to fail websocket handshakes.'
                ));
            } else {
                $promise = new Failure(new \DomainException(
                    sprintf('Invalid close yield: unkown clientId (%d)', $clientId)
                ));
            }

            goto return_struct;
        }

        immediately: {
            if (is_callable($current)) {
                $func = $this->wrapWatcherCallback($rs, $current);
                $watcherId = $this->reactor->immediately($func);
                $promise = new Success($watcherId);
            } else {
                $promise = new Failure(new \DomainException(
                    sprintf(
                        '"%s" yield requires callable; %s provided',
                        $key,
                        gettype($current)
                    )
                ));
            }

            goto return_struct;
        }

        schedule: {
            if ($current && isset($current[0], $current[1]) && is_array($current)) {
                list($func, $msDelay) = $current;
                $func = $this->wrapWatcherCallback($rs, $func);
                $watcherId = $this->reactor->{$key}($func, $msDelay);
                $promise = new Success($watcherId);
            } else {
                $promise = new Failure(new \DomainException(
                    sprintf(
                        '"%s" yield requires [callable $func, int $msDelay]; %s provided',
                        $key,
                        gettype($current)
                    )
                ));
            }

            goto return_struct;
        }

        stream_io_watcher: {
            if ($current && isset($current[0], $current[1], $current[2]) && is_array($current)) {
                list($stream, $func, $enableNow) = $current;
                $func = $this->wrapWatcherCallback($rs, $func);
                $watcherId = $this->reactor->{$ioWatchMethod}($stream, $func, $enableNow);
                $promise = new Success($watcherId);
            } else {
                $promise = new Failure(new \DomainException(
                    sprintf(
                        '"%s" yield requires [resource $stream, callable $func, bool $enableNow]; %s provided',
                        $key,
                        gettype($current)
                    )
                ));
            }

            goto return_struct;
        }

        watcher_control: {
            $this->reactor->{$key}($current);
            $promise = new Success;

            goto return_struct;
        }

        wait: {
            $promisor = $promise = new Future($this->reactor);
            $this->reactor->once(function() use ($promisor) {
                $promisor->succeed();
            }, (int) $current);

            goto return_struct;
        }

        combinator: {
            $promises = [];
            foreach ($current as $index => $element) {
                if ($element instanceof Promise) {
                    $promises[$index] = $element;
                } elseif ($element instanceof \Generator) {
                    $promises[$index] = $this->resolve($element);
                } else {
                    $promises[$index] = new Success($element);
                }
            }

            $promise = $this->combinator->{$key}($promises);

            goto return_struct;
        }

        inspect: {
            $promise = new Success([
                'bytes_read'    => $session->bytesRead,
                'bytes_sent'    => $session->bytesSent,
                'frames_read'   => $session->framesRead,
                'frames_sent'   => $session->framesSent,
                'messages_read' => $session->messagesRead,
                'messages_sent' => $session->messagesSent,
                'connected_at'  => $session->connectedAt,
                'last_read_at'  => $session->lastReadAt,
                'last_send_at'  => $session->lastSendAt,
            ]);
            goto return_struct;
        }

        status: {
            if ($session->handshakeState) {
                $promise = new Failure(new \LogicException(
                    'Cannot assign status code: handshake already initiated'
                ));
            } elseif ($current >= 400 && $current <= 599) {
                $session->handshakeHttpStatus = (int) $current;
                $promise = new Success($session->handshakeHttpStatus);
            } else {
                $promise = new Failure(new \DomainException(
                    'Cannot assign status code: integer in the range [400-599] required'
                ));
            }

            goto return_struct;
        }

        reason: {
            if ($session->handshakeState) {
                $promise = new Failure(new \LogicException(
                    'Cannot assign reason phrase: handshake already initiated'
                ));
            } else {
                $session->handshakeReason = $reason = (string) $current;
                $promise = new Success($reason);
            }

            goto return_struct;
        }

        header: {
            if ($session->handshakeState) {
                $promise = new Failure(new \LogicException(
                    'Cannot assign header: handshake already initiated'
                ));
            } elseif (is_array($current)) {
                $session->handshakeHttpHeader += $current;
                $promise = new Success($current);
            } elseif (is_string($current)) {
                $session->handshakeHttpHeader[] = (string) $current;
                $promise = new Success($current);
            } else {
                $promise = new Failure(new \DomainException(
                    sprintf(
                        '"header" key expects a string or array of strings; %s yielded',
                        gettype($current)
                    )
                ));
            }

            goto return_struct;
        }

        return_struct: {
            return [$promise, $noWait];
        }
    }

    private function wrapWatcherCallback(ResolverStruct $rs, callable $func) {
        return function($reactor, $watcherId, $stream = null) use ($rs, $func) {
            try {
                $result = $stream
                    ? $func($reactor, $watcherId, $stream)
                    : $func($reactor, $watcherId);
                if ($result instanceof \Generator) {
                    $this->resolveGenerator($result, $rs->session);
                }
            } catch (\Exception $e) {
                $errorLogger = $this->errorLogger;
                $errorLogger($e);
            }
        };
    }

    private function sendToGenerator(ResolverStruct $rs, \Exception $error = null, $result = null) {
        try {
            if ($error) {
                $rs->generator->throw($error);
            } else {
                $rs->generator->send($result);
            }
            $this->advanceGenerator($rs, $result);
        } catch (\Exception $error) {
            $rs->promisor->fail($error);
        }
    }

    public function send($payload, array $sendTo = [], array $exclude = []) {
        $recipients = empty($sendTo)
            ? $this->sessions
            : array_intersect_key($this->sessions, array_flip($sendTo));

        if ($exclude) {
            $recipients = array_diff_key($recipients, array_flip($exclude));
        }

        if (empty($recipients)) {
            return new Success;
        }

        if ($this->textOnly) {
            $opcode = Frame::OP_TEXT;
        } else {
            $opcode = preg_match('//u', $payload) ? Frame::OP_TEXT : Frame::OP_BIN;
        }

        $frameStructs = $this->generateDataFrameStructs($opcode, $payload);

        $promises = [];
        foreach ($recipients as $session) {
            if ($session->closeState & Session::CLOSE_INIT) {
                $promise = new Failure(new ClientGoneException(
                    'Cannot send: close handshake initiated'
                ));
            } else {
                $promise = new Future($this->reactor);
                $session->writeDataQueue[] = [$frameStructs, $promise];
                $this->write($session);
            }
            $promises[] = $promise;
        }

        return (count($promises) > 1) ? $this->combinator->any($promises) : current($promises);
    }

    private function generateDataFrameStructs($opcode, $payload) {
        $frames = [];
        $framesNeeded = ceil(strlen($payload) / $this->autoFrameSize);

        if ($framesNeeded > 1) {
            $frameStartPos = 0;
            for ($i=0;$i<$framesNeeded-1;$i++) {
                $chunk = substr($payload, $frameStartPos, $this->autoFrameSize);
                $frames[] = $this->buildFrameStruct($opcode, $chunk, $fin=0);
                $frameStartPos += $this->autoFrameSize;
            }
            $chunk = substr($payload, $frameStartPos);
            $frames[] = $this->buildFrameStruct($opcode, $chunk, $fin=1);
        } else {
            $frames[] = $this->buildFrameStruct($opcode, $payload, $fin=1);
        }

        return $frames;
    }

    private function buildFrameStruct($opcode, $payload, $fin, $rsv = 0, $mask = null) {
        $length = strlen($payload);

        if ($length > 0xFFFF) {
            // Yes, this limits payloads to 2.1GB. Whatever ...
            // @TODO update to use new pack() functionality in 5.6.2
            $lengthHeader = 0x7F;
            $lengthBody = "\x00\x00\x00\x00" . pack('N', $length);
        } elseif ($length > 0x7D) {
            $lengthHeader = 0x7E;
            $lengthBody = pack('n', $length);
        } else {
            $lengthHeader = $length;
            $lengthBody = '';
        }

        $firstByte = 0x00;
        $firstByte |= ((int) $fin) << 7;
        $firstByte |= $rsv << 4;
        $firstByte |= $opcode;

        $hasMask = isset($mask);

        $secondByte = 0x00;
        $secondByte |= ((int) $hasMask) << 7;
        $secondByte |= $lengthHeader;

        $firstWord = chr($firstByte) . chr($secondByte);

        if ($length && $hasMask) {
            $payload = $payload ^ str_pad('', $length, $mask, STR_PAD_RIGHT);
        }

        $frame = $firstWord . $lengthBody . $mask . $payload;

        return [$frame, $opcode, $fin];
    }

    private function read(Session $session) {
        $data = @fread($session->socket, $this->readGranularity);

        if ($data != '') {
            $dataLen = strlen($data);
            $session->parseState->buffer .= $data;
            $session->parseState->bufferSize += $dataLen;
            $session->lastReadAt = $this->now;
            $session->bytesRead += $dataLen;
            $this->renewHeartbeatTimeout($session->clientId);
            $this->parse($session);
        } elseif (!is_resource($session->socket) || feof($session->socket)) {
            $session->closeCode = Codes::ABNORMAL_CLOSE;
            $session->closeReason = "Socket connection severed";
            $this->unloadSession($session);
        }
    }

    private function parse(Session $session) {
        while ($frameStruct = $this->parseRfc6455($session->parseState)) {
            switch (array_shift($frameStruct)) {
                case ParseState::PARSE_FRAME:
                    $this->receiveFrame($session, $frameStruct);
                    break;
                case ParseState::PARSE_ERR_SYNTAX:
                    $errorMsg = $frameStruct[0];
                    if ($session->closeState & Session::CLOSE_INIT) {
                        // If the close has already been initiated we don't tolerate
                        // errors and immediately unload the client session.
                        $this->unloadSession($session);
                    } else {
                        $this->closeSession($session, Codes::PROTOCOL_ERROR, $errorMsg);
                    }
                    break;
                case ParseState::PARSE_ERR_POLICY:
                    $errorMsg = $frameStruct[0];
                    $session->closeCode = Codes::POLICY_VIOLATION;
                    $session->closeReason = $errorMsg;
                    $this->unloadSession($session);
                    break;
                default:
                    throw new \UnexpectedValueException(
                        'Unexpected frame parse result code'
                    );
            }
        }
    }

    private function parseRfc6455(ParseState $ps) {
        if ($ps->bufferSize === 0) {
            goto more_data_needed;
        }

        switch ($ps->state) {
            case ParseState::START:
                goto start;
            case ParseState::LENGTH_126:
                goto determine_length_126;
            case ParseState::LENGTH_127:
                goto determine_length_127;
            case ParseState::MASKING_KEY:
                goto determine_masking_key;
            case ParseState::CONTROL_PAYLOAD:
                goto payload;
            case ParseState::PAYLOAD:
                goto payload;
            default:
                throw new \UnexpectedValueException(
                    'Unexpected frame parsing state'
                );
        }

        start: {
            if ($ps->bufferSize < 2) {
                goto more_data_needed;
            }

            $firstByte = ord($ps->buffer[0]);
            $secondByte = ord($ps->buffer[1]);

            $ps->buffer = substr($ps->buffer, 2);
            $ps->bufferSize -= 2;

            $ps->fin = (bool) ($firstByte & 0b10000000);
            $ps->rsv = ($firstByte & 0b01110000) >> 4;
            $ps->opcode = $firstByte & 0b00001111;
            $ps->isMasked = (bool) ($secondByte & 0b10000000);
            $ps->maskingKey = null;
            $ps->frameLength = $secondByte & 0b01111111;

            $ps->isControlFrame = ($ps->opcode >= 0x08);

            if ($ps->frameLength === 0x7E) {
                $ps->state = ParseState::LENGTH_126;
                goto determine_length_126;
            } elseif ($ps->frameLength === 0x7F) {
                $ps->state = ParseState::LENGTH_127;
                goto determine_length_127;
            } else {
                goto validate_header;
            }
        }

        determine_length_126: {
            if ($ps->bufferSize < 2) {
                goto more_data_needed;
            } else {
                $ps->frameLength = current(unpack('n', $ps->buffer[0] . $ps->buffer[1]));
                $ps->buffer = substr($ps->buffer, 2);
                $ps->bufferSize -= 2;
                goto validate_header;
            }
        }

        determine_length_127: {
            if ($ps->bufferSize < 8) {
                goto more_data_needed;
            }

            $lengthLong32Pair = array_values(unpack('N2', substr($ps->buffer, 0, 8)));
            $ps->buffer = substr($ps->buffer, 8);
            $ps->bufferSize -= 8;

            if (PHP_INT_MAX === 0x7fffffff) {
                goto validate_length_127_32bit;
            } else {
                goto validate_length_127_64bit;
            }
        }

        validate_length_127_32bit: {
            if ($lengthLong32Pair[0] !== 0 || $lengthLong32Pair[1] < 0) {
                $resultCode = ParseState::PARSE_ERR_POLICY;
                $errorMsg = 'Payload exceeds maximum allowable size';
                goto error;
            }
            $ps->frameLength = $lengthLong32Pair[1];

            goto validate_header;
        }

        validate_length_127_64bit: {
            $length = ($lengthLong32Pair[0] << 32) | $lengthLong32Pair[1];
            if ($length < 0) {
                throw new ParseException(
                    'Most significant bit of 64-bit length field set'
                );
            }
            $ps->frameLength = $length;

            goto validate_header;
        }

        validate_header: {
            if ($ps->isControlFrame && !$ps->fin) {
                $resultCode = ParseState::PARSE_ERR_SYNTAX;
                $errorMsg = 'Illegal control frame fragmentation';
                goto error;
            } elseif ($this->maxFrameSize && $ps->frameLength > $this->maxFrameSize) {
                $resultCode = ParseState::PARSE_ERR_POLICY;
                $errorMsg = 'Payload exceeds maximum allowable frame size';
                goto error;
            } elseif ($this->maxMsgSize && ($ps->frameLength + $ps->dataMsgBytesRecd) > $this->maxMsgSize) {
                $resultCode = ParseState::PARSE_ERR_POLICY;
                $errorMsg = 'Payload exceeds maximum allowable message size';
                goto error;
            } elseif ($this->textOnly && $ps->opcode === 0x02) {
                $resultCode = ParseState::PARSE_ERR_POLICY;
                $errorMsg = 'BINARY opcodes (0x02) not accepted';
                goto error;
            } elseif ($ps->frameLength > 0 && !$ps->isMasked) {
                $resultCode = ParseState::PARSE_ERR_SYNTAX;
                $errorMsg = 'Payload mask required';
                goto error;
            } elseif (!($ps->opcode || $ps->isControlFrame)) {
                $resultCode = ParseState::PARSE_ERR_SYNTAX;
                $errorMsg = 'Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY';
                goto error;
            }

            if (!$ps->frameLength) {
                goto frame_complete;
            } else {
                $ps->state = ParseState::MASKING_KEY;
                goto determine_masking_key;
            }
        }

        determine_masking_key: {
            if (!$ps->isMasked && $ps->isControlFrame) {
                $ps->state = ParseState::CONTROL_PAYLOAD;
                goto payload;
            } elseif (!$ps->isMasked) {
                $ps->state = ParseState::PAYLOAD;
                goto payload;
            } elseif ($ps->bufferSize < 4) {
                goto more_data_needed;
            }

            $ps->maskingKey = substr($ps->buffer, 0, 4);
            $ps->buffer = substr($ps->buffer, 4);
            $ps->bufferSize -= 4;

            if (!$ps->frameLength) {
                goto frame_complete;
            } elseif ($ps->isControlFrame) {
                $ps->state = $ps->isControlFrame ? ParseState::CONTROL_PAYLOAD : ParseState::PAYLOAD;
                goto payload;
            }

            goto payload;
        }

        payload: {
            $dataLen = (($ps->bufferSize + $ps->frameBytesRecd) >= $ps->frameLength)
                ? $ps->frameLength - $ps->frameBytesRecd
                : $ps->bufferSize;

            $data = substr($ps->buffer, 0, $dataLen);
            $ps->frameBytesRecd += $dataLen;

            if ($ps->isMasked) {
                // This is memory hungry but it's ~70x faster than iterating byte-by-byte
                // over the masked string. Deal with it; manual iteration is untenable.
                $data ^= str_pad('', $dataLen, $ps->maskingKey, STR_PAD_RIGHT);
            }

            if ($ps->opcode === Frame::OP_TEXT
                && $this->validateUtf8
                && !preg_match('//u', $data)
            ) {
                $resultCode = ParseState::PARSE_ERR_SYNTAX;
                $errorMsg = 'Invalid TEXT data; UTF-8 required';
                goto error;
            }

            $ps->buffer = substr($ps->buffer, $dataLen);
            $ps->bufferSize -= $dataLen;

            if ($ps->state === ParseState::CONTROL_PAYLOAD) {
                $payloadReference =& $this->controlPayload;
            } else {
                $payloadReference =& $this->dataPayload;
                $ps->dataMsgBytesRecd += $dataLen;
            }

            $payloadReference .= $data;

            if ($ps->frameBytesRecd == $ps->frameLength) {
                goto frame_complete;
            } else {
                goto more_data_needed;
            }
        }

        frame_complete: {
            $payloadReference = isset($payloadReference) ? $payloadReference : '';
            $frameStruct = [ParseState::PARSE_FRAME, $payloadReference, $ps->opcode, $ps->fin];
            $payloadReference = '';

            if ($ps->fin && $ps->opcode < Frame::OP_CLOSE) {
                $ps->dataMsgBytesRecd = 0;
            }

            $ps->state = ParseState::START;
            $ps->fin = null;
            $ps->rsv = null;
            $ps->opcode = null;
            $ps->isMasked = null;
            $ps->maskingKey = null;
            $ps->frameLength = null;
            $ps->frameBytesRecd = 0;
            $ps->isControlFrame = null;

            return $frameStruct;
        }

        error: {
            return [$resultCode, $errorMsg];
        }

        more_data_needed: {
            return null;
        }
    }

    private function receiveFrame(Session $session, array $parseStruct) {
        list($payload, $opcode, $fin) = $parseStruct;

        $session->framesRead++;
        $session->messagesRead += $fin;

        switch ($opcode) {
            case Frame::OP_PING:
                $this->receivePing($session, $payload);
                break;
            case Frame::OP_PONG:
                $this->receivePong($session, $payload);
                break;
            case Frame::OP_CLOSE:
                $this->receiveClose($session, $payload);
                break;
            default:
                $this->receiveData($session, $payload, $fin);
        }
    }

    private function receiveData(Session $session, $payload, $fin) {
        $session->messageBuffer .= $payload;
        if ($fin) {
            $payload = $session->messageBuffer;
            $session->messageBuffer = '';
            $this->notifyApplicationOnData($session, $payload);
        }
    }

    private function receivePing(Session $session, $payload) {
        $frameStruct = $this->buildFrameStruct(Frame::OP_PONG, $payload, $fin=1);
        $session->writeControlQueue[] = [[$frameStruct], null];
        $this->write($session);
    }

    private function receivePong(Session $session, $payload) {
        $pendingPingCount = count($session->pendingPings);

        for ($i=$pendingPingCount-1; $i>=0; $i--) {
            if ($session->pendingPings[$i] == $payload) {
                $session->pendingPings = array_slice($session->pendingPings, $i+1);
                break;
            }
        }
    }

    private function receiveClose(Session $session, $payload) {
        $session->closeState |= Session::CLOSE_RCVD;

        if ($session->closeState === Session::CLOSE_DONE) {
            $this->unloadSession($session);
        } else {
            @stream_socket_shutdown($session->socket, STREAM_SHUT_RD);
            list($code, $reason) = $this->parseCloseFramePayload($payload);
            $this->closeSession($session, $code, $reason);
        }
    }

    private function parseCloseFramePayload($payload) {
        if (strlen($payload) >= 2) {
            $code = (int) unpack('nstatus', substr($payload, 0, 2))['status'];
            if ($code < Codes::MIN || $code > Codes::MAX) {
                $code = Codes::NONE;
            }
            $codeAndReason = [$code, (string) substr($payload, 2, 125)];
        } else {
            $codeAndReason = [Codes::NONE, ''];
        }

        return $codeAndReason;
    }

    private function write(Session $session) {
        start: {
            if ($session->writeBufferSize) {
                goto write;
            } elseif ($session->writeControlQueue) {
                $queue =& $session->writeControlQueue;
                goto dequeue_next_frame;
            } elseif ($session->writeDataQueue) {
                $queue =& $session->writeDataQueue;
                goto dequeue_next_frame;
            } else {
                goto all_data_sent;
            }
        }

        dequeue_next_frame: {
            $key = key($queue);
            list($buffer, $session->writeOpcode, $session->writeIsFin) = array_shift($queue[$key][0]);
            $session->writeBuffer = $session->writeBufferSize ? ($session->writeBuffer . $buffer) : $buffer;
            $session->writeBufferSize = strlen($session->writeBuffer);
            goto write;
        }

        write: {
            $bytesWritten = @fwrite($session->socket, $session->writeBuffer);
            if ($bytesWritten === false) {
                goto socket_gone;
            } else {
                goto after_write;
            }
        }

        after_write: {
            $session->writeBufferSize -= $bytesWritten;

            if ($session->handshakeState !== Session::HANDSHAKE_DONE) {
                goto after_handshake_write;
            }

            // Don't start tracking send stats until after the handshake completes
            $session->lastSendAt = $this->now;
            $session->bytesSent += $bytesWritten;
            $this->renewHeartbeatTimeout($session->clientId);

            if ($session->writeBufferSize === 0) {
                $session->writeBuffer = '';
                goto after_completed_frame_write;
            } else {
                $session->writeBuffer = substr($session->writeBuffer, $bytesWritten);
                goto further_write_needed;
            }
        }

        after_handshake_write: {
            if ($session->writeBufferSize > 0) {
                $session->writeBuffer = substr($session->writeBuffer, $bytesWritten);
                goto further_write_needed;
            } elseif ($session->handshakeHttpStatus) {
                // An non-empty status code means we sent an HTTP error response for the
                // handshake. We now need to close the socket and unload the session.
                @fclose($session->socket);
                $this->unloadSession($session);
                return;
            } else {
                $session->writeBuffer = '';
                $session->handshakeState = Session::HANDSHAKE_DONE;
                $onReadable = function() use ($session) { $this->read($session); };
                $session->readWatcher = $this->reactor->onReadable($session->socket, $onReadable);
                $this->renewHeartbeatTimeout($session->clientId);
                if (count($this->sessions) === 1) {
                    // If this is the only connected session we need to enable timeout watching.
                    // This watcher is disabled when no clients are connected to avoid waking the
                    // script for no reason.
                    $this->reactor->enable($this->timeoutWatcher);
                }
                goto start;
            }
        }

        after_completed_frame_write: {
            $session->framesSent++;
            $session->messagesSent += $session->writeIsFin;

            if ($session->writeOpcode >= Frame::OP_CLOSE) {
                goto after_control_message;
            } elseif ($session->writeIsFin) {
                goto after_data_message;
            } else {
                goto further_write_needed;
            }
        }

        after_control_message: {
            $key = key($session->writeControlQueue);
            $promisor = $session->writeControlQueue[$key][1];
            unset($session->writeControlQueue[$key]);
            if ($promisor) {
                $promisor->succeed();
            }

            if ($session->writeOpcode === Frame::OP_CLOSE) {
                goto after_close_message;
            } elseif ($session->writeDataQueue || $session->writeControlQueue) {
                goto further_write_needed;
            } else {
                goto all_data_sent;
            }
        }

        after_close_message: {
            $this->reactor->disable($session->writeWatcher);
            $session->closeState |= Session::CLOSE_SENT;

            if ($session->closeState & Session::CLOSE_DONE) {
                $this->unloadSession($session);
            } else {
                @stream_socket_shutdown($session->socket, STREAM_SHUT_WR);
                $this->closeTimeouts[$session->clientId] = $this->now + $this->closePeriod;
            }

            return;
        }

        after_data_message: {
            $key = key($session->writeDataQueue);
            $promisor = $session->writeDataQueue[$key][1];
            unset($session->writeDataQueue[$key]);
            $promisor->succeed();
            if ($session->writeDataQueue || $session->writeControlQueue) {
                goto further_write_needed;
            } else {
                goto all_data_sent;
            }
        }

        further_write_needed: {
            if (!$session->isWriteWatcherEnabled) {
                $session->isWriteWatcherEnabled = true;
                $this->reactor->enable($session->writeWatcher);
            }
            return;
        }

        all_data_sent: {
            if ($session->isWriteWatcherEnabled) {
                $session->isWriteWatcherEnabled = false;
                $this->reactor->disable($session->writeWatcher);
            }
            return;
        }

        socket_gone: {
            $session->closeCode = Codes::ABNORMAL_CLOSE;
            $session->closeReason = "Socket connection severed unexpectedly";
            $this->unloadSession($session);
            return;
        }
    }

    private function renewHeartbeatTimeout($clientId) {
        if ($this->heartbeatPeriod > 0) {
            unset($this->heartbeatTimeouts[$clientId]);
            $this->heartbeatTimeouts[$clientId] = $this->now + $this->heartbeatPeriod;
        }
    }

    public function timeout() {
        $this->now = $now = time();

        foreach ($this->heartbeatTimeouts as $clientId => $expiryTime) {
            if ($expiryTime < $now) {
                $session = $this->sessions[$clientId];
                unset($this->heartbeatTimeouts[$clientId]);
                $this->heartbeatTimeouts[$clientId] = $now;
                $this->sendHeartbeatPing($session);
            } else {
                break;
            }
        }

        foreach ($this->closeTimeouts as $clientId => $expiryTime) {
            if ($expiryTime < $now) {
                $session = $this->sessions[$clientId];
                $session->closeCode = Codes::ABNORMAL_CLOSE;
                $session->closeReason = 'CLOSE handshake timeout';
                $this->unloadSession($session);
            } else {
                break;
            }
        }
    }

    private function sendHeartbeatPing(Session $session) {
        $ord = rand(48, 90);
        $payload = chr($ord);

        if (array_push($session->pendingPings, $payload) > $this->queuedPingLimit) {
            $code = Codes::POLICY_VIOLATION;
            $reason = 'Exceeded unanswered PING limit';
            $this->closeSession($session, $code, $reason);
        } else {
            $frameStruct = $this->buildFrameStruct(Frame::OP_PING, $payload, $fin=1);
            $session->writeControlQueue[] = [[$frameStruct], null];
            $this->write($session);
        }
    }

    public function close($clientId, $code = Codes::NORMAL_CLOSE, $reason = '') {
        if (!isset($this->sessions[$clientId])) {
            return new Failure(new \DomainException(
                sprintf("Unknown clientId: %s", $clientId)
            ));
        }

        $code = (int) $code;
        if ($code < Codes::MIN || $code > Codes::MAX) {
            $code = Codes::NONE;
        }

        // RFC6455 limits close reasons to 125 characters
        $reason = (string) $reason;
        if (isset($reason[125])) {
            $reason = substr($reason, 0, 125);
        }

        $session = $this->sessions[$clientId];

        return $this->closeSession($session, $code, $reason);
    }

    private function closeSession(Session $session, $code, $reason = '') {
        if (!($session->closeState & Session::CLOSE_INIT)) {
            $promisor = new Future($this->reactor);
            $session->closeState |= Session::CLOSE_INIT;
            $session->closePromisor = $promisor;
            $session->closeCode = $code;
            $session->closeReason = isset($reason[125]) ? substr($reason, 0, 125) : $reason;
            $session->closePayload = pack('S', $code) . $reason;
            $frameStruct = $this->buildFrameStruct(Frame::OP_CLOSE, $session->closePayload, $fin=1);
            $session->writeControlQueue[] = [[$frameStruct], $promisor];
            $this->write($session);
        } elseif ($session->closeState & Session::CLOSE_DONE) {
            $this->unloadSession($session);
            $promisor = $session->closePromisor;
        }

        return $promisor;
    }

    private function unloadSession(Session $session) {
        if ($session->readWatcher) {
            $this->reactor->cancel($session->readWatcher);
        }
        if ($session->writeWatcher) {
            $this->reactor->cancel($session->writeWatcher);
        }

        foreach ($session->writeDataQueue as $framesAndPromise) {
            end($framesAndPromise)->fail(new ClientGoneException);
        }

        foreach ($session->writeControlQueue as $framesAndPromise) {
            list(,$promise) = $framesAndPromise;
            // Only close frames have an associated promise
            if ($promise) {
                $promise->succeed();
            }
        }

        $clientId = $session->clientId;
        unset(
            $this->sessions[$clientId],
            $this->closeTimeouts[$clientId],
            $this->heartbeatTimeouts[$clientId]
        );

        // Don't wake up every 1000ms to execute timeouts if no clients are connected
        if (empty($this->sessions)) {
            $this->reactor->disable($this->timeoutWatcher);
        }

        // Inform the HTTP server that we're finished with this socket. This is critically
        // important as the server *will not* release resources associated with exported
        // sockets until told to do so via this callback.
        $serverOnCloseCallback = $session->serverCloseCallback;
        $serverOnCloseCallback();

        // Only notify onClose if the application didn't fail the websocket handshake with
        // a custom HTTP error response.
        if (empty($session->handshakeHttpStatus)) {
            $this->notifyApplicationOnClose($session);
        }
    }

    /**
     * @TODO Documentation
     */
    public function setAllOptions(array $options) {
        foreach ($options as $option => $value) {
            $this->setOption($option, $value);
        }
    }

    /**
     * @TODO Documentation
     */
    public function setOption($option, $value) {
        switch ($option) {
            case self::OP_MAX_FRAME_SIZE:
                $this->setMaxFrameSize($value);
                break;
            case self::OP_MAX_MSG_SIZE:
                $this->setMaxMsgSize($value);
                break;
            case self::OP_HEARTBEAT_PERIOD:
                $this->setHearbeatPeriod($value);
                break;
            case self::OP_CLOSE_PERIOD:
                $this->setClosePeriod($value);
                break;
            case self::OP_VALIDATE_UTF8:
                $this->setValidateUtf8($value);
                break;
            case self::OP_TEXT_ONLY:
                $this->setTextOnly($value);
                break;
            case self::OP_AUTO_FRAME_SIZE:
                $this->setAutoFrameSize($value);
                break;
            case self::OP_QUEUED_PING_LIMIT:
                $this->setQueuedPingLimit($value);
                break;
            default:
                throw new \DomainException(
                    sprintf("Unknown websocket option: %s", $option)
                );
        }
    }

    private function setTextOnly($bool) {
        $this->textOnly = (bool) $bool;
    }

    private function setValidateUtf8($bool) {
        $this->validateUtf8 = (bool) $bool;
    }

    private function setMaxFrameSize($bytes) {
        $bytes = (int) $bytes;
        if ($bytes < 1) {
            $bytes = 2097152;
        }
        $this->maxFrameSize = $bytes;
    }

    private function setMaxMsgSize($bytes) {
        $bytes = (int) $bytes;
        if ($bytes < 1) {
            $bytes = 10485760;
        }
        $this->maxMsgSize = $bytes;
    }

    private function setHeartbeatPeriod($seconds) {
        $seconds = (int) $seconds;
        if ($seconds < 1) {
            $seconds = 10;
        }
        $this->heartbeatPeriod = $seconds;
    }

    private function setAutoFrameSize($bytes) {
        $bytes = (int) $bytes;
        if ($bytes < 1) {
            $bytes = 32768;
        }
        $this->autoFrameSize = $bytes;
    }

    private function setClosePeriod($seconds) {
        $seconds = (int) $seconds;
        if ($seconds < 1) {
            $seconds = 3;
        }
        $this->closePeriod = $seconds;
    }

    private function setQueuedPingLimit($limit) {
        $limit = (int) $limit;
        if ($limit < 1) {
            $limit = 5;
        }
        $this->queuedPingLimit = $limit;
    }
}
