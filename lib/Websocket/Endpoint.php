<?php

namespace Aerys\Websocket;

use Amp\Reactor;
use Amp\Future;
use Amp\Success;
use Amp\Failure;
use Amp\Combinator;
use Aerys\Server;
use Aerys\Websocket;
use Aerys\ServerObserver;

class Endpoint implements ServerObserver {
    const OP_ALLOWED_ORIGINS = 1;
    const OP_MAX_FRAME_SIZE = 2;
    const OP_MAX_MSG_SIZE = 3;
    const OP_HEARTBEAT_PERIOD = 4;
    const OP_CLOSE_PERIOD = 5;
    const OP_VALIDATE_UTF8 = 6;
    const OP_TEXT_ONLY = 7;
    const OP_SUBPROTOCOL = 8;
    const OP_AUTO_FRAME_SIZE = 9;
    const OP_QUEUED_PING_LIMIT = 11;
    const OP_NOTIFY_FRAMES = 12;

    private $reactor;
    private $endpoint;
    private $combinator;
    private $state = Sever::STOPPED;

    private $sessions = [];
    private $closeTimeouts = [];
    private $heartbeatTimeouts = [];
    private $timeoutWatcher;
    private $now;

    private $allowedOrigins = [];
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
    // @TODO We don't currently support any subprotocols
    private $subprotocols = [];
    // @TODO add minimum average frame size rate threshold to prevent tiny-frame DoS

    private $errorLogger;

    private static $RESOLVE_OPEN = 1;
    private static $RESOLVE_DATA = 2;
    private static $RESOLVE_CLOSE = 3;

    public function __construct(Reactor $reactor, Websocket $websocket, Combinator $combinator = null) {
        $this->reactor = $reactor;
        $this->websocket = $websocket;
        $this->combinator = $combinator ?: new Combinator($reactor);
        $this->errorLogger = function($error) {
            if (!$error instanceof ClientGoneException) {
                fwrite(STDERR, $e);
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
        try {
            $result = $this->endpoint->onStart();
            if ($result instanceof \Generator) {
                $promise = $this->resolveGenerator($result);
            } elseif ($result instanceof Promise) {
                $promise = $result;
            } else {
                $promise = new Success($result);
            }

            $promise->when(function($e, $r) {
                if (empty($e)) {
                    $this->now = time();
                    $this->timeoutWatcher = $this->reactor->repeat([$this, 'timeout'], $msInterval = 1000);
                    $this->reactor->disable($this->timeoutWatcher);
                }
            });

        } catch (\Exception $e) {
            return new Failure($e);
        }
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
            $promisor->succeed($this->notifyEndpointOnStop());
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
    public function import($socket, callable $closer, array $request) {
        $session = new Session;

        $clientId = (int) $socket;
        $session->serverCloseCallback = $closer;
        $session->connectedAt = $this->now;
        $session->clientId = $clientId;
        $session->socket = $socket;
        $session->parser = [$this, 'parseRfc6455'];
        $session->parseState = new ParseState;
        $session->writeState = new SessionWriteState;
        $session->readWatcher = $this->reactor->onReadable($socket, function() use ($session) {
            $this->read($session);
        });
        $session->writeWatcher = $this->reactor->onWritable($socket, function() use ($session) {
            $this->write($session);
        }, $enableNow = false);

        if (empty($this->sessions)) {
            $this->reactor->enable($this->timeoutWatcher);
        }

        $this->sessions[$clientId] = $session;
        $this->renewHeartbeatTimeout($clientId);
        $this->notifyEndpointOnOpen($clientId, $request);
    }

    private function renewHeartbeatTimeout($clientId) {
        if ($this->heartbeatPeriod > 0) {
            unset($this->heartbeatTimeouts[$clientId]);
            $this->heartbeatTimeouts[$clientId] = $this->now + $this->heartbeatPeriod;
        }
    }

    private function notifyEndpointOnOpen($clientId, $httpEnvironment) {
        try {
            $result = $this->endpoint->onOpen($clientId, $httpEnvironment);
            if ($result instanceof \Generator) {
                $this->resolveGenerator($result, $clientId);
            }
        } catch (\Exception $e) {
            // @TODO Log error (app threw uncaught exception)
            echo $e;
        }
    }

    private function notifyEndpointOnData($clientId, $payload, $context) {
        try {
            $result = $this->endpoint->onData($clientId, $payload, $context);
            if ($result instanceof \Generator) {
                $this->resolveGenerator($result, $clientId);
            }
        } catch (\Exception $e) {
            // @TODO Log error (app threw uncaught exception)
            echo $e;
        }
    }

    private function notifyEndpointOnClose($clientId, $code, $reason) {
        try {
            $result = $this->endpoint->onClose($clientId, $code, $reason);
            if ($result instanceof \Generator) {
                $this->resolveGenerator($result, $clientId);
            }
        } catch (\Exception $e) {
            // @TODO Log error (app threw uncaught exception)
            echo $e;
        }
    }

    private function resolveGenerator(\Generator $generator, $clientId) {
        $promisor = new Future($this->reactor);
        $promisor->when($this->errorLogger);

        $rs = new ResolverStruct;
        $rs->generator = $generator;
        $rs->promisor  = $promisor;
        $rs->clientId  = $clientId;

        $this->advanceGenerator($rs);

        return $promisor;
    }

    private function advanceGenerator(ResolverStruct $rs, $previousResult = null) {
        try {
            $generator = $rs->generator;
            if ($generator->valid()) {
                $key = $generator->key();
                $current = $generator->current();
                $promise = $this->promisifyYield($key, $current);
                $this->reactor->immediately(function() use ($rs) {
                    $promise->when(function($error, $result) use ($rs) {
                        $this->sendToGenerator($rs, $error, $result);
                    });
                });
            } else {
                $rs->promisor->succeed($previousResult);
            }
        } catch (\Exception $error) {
            $rs->promisor->fail($error);
        }
    }

    private function promisifyYield($rs, $key, $current) {
        if ($current instanceof Promise) {
            return $current;
        } elseif ($key === (string) $key) {
            goto explicit_key;
        } else {
            goto implicit_key;
        }

        explicit_key: {
            switch ($key) {
                case Websocket::SEND:
                    return isset($rs->clientId)
                        ? $this->send($current, [$rs->clientId])
                        : new Failure(new \LogicException(
                            'Cannot execute implicit send: ambiguous client target'
                        ));
                case Websocket::BROADCAST:
                    return $this->send($current, []);
                case Websocket::INSPECT:
                    return $this->inspect($current);
                case Websocket::CLOSE:
                    return $this->close($current);
                case Websocket::WATCH_STREAM:
                    goto watch_stream;
                case Websocket::IMMEDIATELY:
                    goto immediately;
                case Websocket::ONCE:
                    // fallthrough
                case Websocket::REPEAT:
                    goto schedule;
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
                default:
                    return new Failure(new \DomainException(
                        sprintf('Unknown yield key: "%s"', $key)
                    ));
            }
        }

        implicit_key: {
            if (is_array($current)) {
                // An array without an explicit key is assumed to be an "all" combinator
                $key = Websocket::ALL;
                goto combinator;
            } elseif ($current instanceof \Generator) {
                return $this->resolveGenerator($current, $rs->clientId);
            } elseif ($current instanceof Send || $current instanceof Broadcast) {
                list($data, $include, $exclude) = $current->toArray();
                return $this->send($data, $include, $exclude);
            } elseif ($current instanceof Close) {
                list($clientId, $code, $reason) = $current->toArray();
                return $this->close($clientId, $code, $reason);
            } else {
                return new Success($current);
            }
        }

        immediately: {
            if (!is_callable($current)) {
                return new Failure(new \DomainException(
                    sprintf(
                        '"%s" yield requires callable; %s provided',
                        $key,
                        gettype($current)
                    )
                ));
            }

            $watcherId = $this->reactor->immediately($current);

            return new Success($watcherId);
        }

        schedule: {
            if (!($current && isset($current[0], $current[1]) && is_array($current))) {
                return new Failure(new \DomainException(
                    sprintf(
                        '"%s" yield requires [callable $func, int $msDelay]; %s provided',
                        $key,
                        gettype($current)
                    )
                ));
            }

            list($func, $msDelay) = $current;
            $watcherId = $this->reactor->{$key}($func, $msDelay);

            return new Success($watcherId);
        }

        watch_stream: {
            if (!($current &&
                isset($current[0], $current[1], $current[2]) &&
                is_array($current) &&
                is_callable($current[1])
            )) {
                return new Failure(new \DomainException(
                    sprintf(
                        '"%s" yield requires [resource $stream, callable $func, int $flags]; %s provided',
                        $key,
                        gettype($current)
                    )
                ));
            }

            list($stream, $callback, $flags) = $current;

            try {
                $watcherId = $this->reactor->watchStream($stream, $callback, $flags);
                return new Success($watcherId);
            } catch (\Exception $error) {
                return new Failure($error);
            }
        }

        watcher_control: {
            $this->reactor->{$key}($current);
            return new Success;
        }

        wait: {
            $promisor = new Future($this->reactor);
            $this->reactor->once(function() use ($promisor) {
                $promisor->succeed();
            }, (int) $current);

            return $promisor;
        }

        combinator: {
            $promises = [];
            foreach ($current as $index => $element) {
                if ($element instanceof Promise) {
                    $promise = $element;
                } elseif ($element instanceof \Generator) {
                    $promise = $this->resolve($element);
                } elseif ($element instanceof Send || $element instanceof Broadcast) {
                    list($data, $include, $exclude) = $current->toArray();
                    $promise = $this->send($data, $include, $exclude);
                } elseif ($element instanceof Close) {
                    list($clientId, $code, $reason) = $current->toArray();
                    $promise = $this->close($clientId, $code, $reason);
                } else {
                    $promise = new Success($element);
                }

                $promises[$index] = $promise;
            }

            return $this->combinator->{$key}($promises);
        }
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

































    /**
     * Retrieve information about the specified client
     *
     * @param int $clientId
     * @return \Amp\Promise
     */
    public function inspect($clientId) {
        if (empty($this->sessions[$clientId]) {
            new Failure(new \DomainException(
                sprintf('Unkown clientId: %s', $clientId)
            ));
        }

        $session = $this->sessions[$clientId];

        return new Success([
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
    }

    /**
     * Send $payload to $recipients excluding any clients listed in the $exclude array
     *
     * @param string $payload
     * @param int|array $sendTo
     * @param array $exclude
     * @return \Amp\Promise
     */
    public function send($payload, array $sendTo = [], array $exclude = []) {
        if ($this->state < Server::STARTED) {
            // This check is necessary because onStart() generators
            // may pointlessly try to send things
            return new Failure(new \LogicException(
                'Cannot send: server has not started'
            ));
        }

        $recipients = empty($sendTo)
            ? $this->sessions
            : array_intersect_key($this->sessions, array_flip($sendTo));

        if ($exclude) {
            $recipients = array_diff_key($recipients, array_flip($exclude));
        }

        if (empty($recipients)) {
            return new Success;
        }

        $opcode = preg_match('//u', $payload) ? Frame::OP_TEXT : Frame::OP_BIN;
        $frameStructs = $this->generateDataFrameStructs($opcode, $payload);

        $promises = [];
        foreach ($recipients as $session) {
            if ($session->closeState & Session::CLOSE_INIT) {
                $promisor = new Failure(new ClientGoneException(
                    'Cannot send: close handshake initiated'
                ));
            } else {
                $promisor = new Future($this);
                $promises[] = $promisor;
                $session->writeState->dataQueue[] = [$frameStructs, $promisor];
                $this->write($session);
            }
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

        if ($data === false) {
            $session->closeCode = Codes::ABNORMAL_CLOSE;
            $session->closeReason = "Socket connection severed";
            $this->endSession($session);
        } else {
            $dataLen = strlen($data);
            $session->parseState->buffer .= $data;

            $session->parseState->bufferSize += $dataLen;
            $session->lastReadAt = $this->now;
            $session->bytesRead += $dataLen;
            $this->renewHeartbeatTimeout($session->clientId);
            $this->parse($session);
        }
    }

    private function parse(Session $session) {
        try {
            $parser = $session->parser;
            while ($parseStruct = $parser($session->parseState)) {
                $this->receiveFrame($session, $parseStruct);
            }
        } catch (PolicyException $e) {
            $this->closeSession($session, Codes::POLICY_VIOLATION, $e->getMessage());
        } catch (ParseException $e) {
            $session->closeCode = Codes::PROTOCOL_ERROR;
            $session->closeReason = $e->getMessage();
            $this->endSession($session);
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
                throw new PolicyException(
                    'Payload exceeds maximum allowable size'
                );
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
                throw new ParseException(
                    'Illegal control frame fragmentation'
                );
            } elseif ($this->maxFrameSize && $ps->frameLength > $this->maxFrameSize) {
                throw new PolicyException(
                    'Payload exceeds maximum allowable frame size'
                );
            } elseif ($this->maxMsgSize && ($ps->frameLength + $ps->dataMsgBytesRecd) > $this->maxMsgSize) {
                throw new PolicyException(
                    'Payload exceeds maximum allowable message size'
                );
            } elseif ($this->textOnly && $ps->opcode === 0x02) {
                throw new PolicyException(
                    'BINARY opcodes (0x02) not accepted'
                );
            } elseif ($ps->frameLength > 0 && !$ps->isMasked) {
                throw new ParseException(
                    'Payload mask required'
                );
            } elseif (!($ps->opcode || $ps->isControlFrame)) {
                throw new ParseException(
                    'Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY'
                );
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
                throw new ParseException(
                    'Invalid TEXT data; UTF-8 required'
                );
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
            $frameStruct = [$payloadReference, $ps->opcode, $ps->fin];
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
        if ($fin || $this->notifyFrames) {
            $payload = $session->messageBuffer;
            $session->messageBuffer = '';
            $this->notifyEndpointOnData($session->clientId, $payload);
        }
    }

    private function receivePing(Session $session, $payload) {
        $frameStruct = $this->buildFrameStruct(Frame::OP_PONG, $payload, $fin=1);
        $session->writeState->controlQueue[] = [$frameStruct, null];
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
        $session->closeState |= Session::CLOSE_RECD;

        if ($session->closeState & Session::CLOSE_DONE) {
            $this->endSession($session);
        } else {
            @stream_socket_shutdown($session->socket, STREAM_SHUT_RD);
            list($code, $reason) = $this->parseCloseFramePayload($payload);
            $this->closeSession($session, $code, $reason);
        }
    }

    private function parseCloseFramePayload($payload) {
        if (strlen($payload) >= 2) {
            $code = unpack('nstatus', substr($payload, 0, 2))['status'];
            $code = filter_var($code, FILTER_VALIDATE_INT, ['options' => [
                'default' => Codes::NONE,
                'min_range' => 1000,
                'max_range' => 4999
            ]]);
            $codeAndReason = [$code, (string) substr($payload, 2, 125)];
        } else {
            $codeAndReason = [Codes::NONE, ''];
        }

        return $codeAndReason;
    }

    private function write(Session $session) {
        $writeState = $session->writeState;

        start: {
            if ($writeState->bufferSize) {
                goto write;
            } elseif ($writeState->controlQueue) {
                $queue =& $writeState->controlQueue;
                goto dequeue_next_frame;
            } elseif ($writeState->dataQueue) {
                $queue =& $writeState->dataQueue;
                goto dequeue_next_frame;
            } else {
                return;
            }
        }

        dequeue_next_frame: {
            $key = key($queue);
            list($writeState->buffer, $writeState->opcode, $writeState->fin) = array_shift($queue[$key][0]);
            $writeState->bufferSize = strlen($writeState->buffer);
            goto write;
        }

        write: {
            $bytesWritten = @fwrite($session->socket, $writeState->buffer);
            if ($bytesWritten === false) {
                goto socket_gone;
            } else {
                goto after_write;
            }
        }

        after_write: {
            $this->renewHeartbeatTimeout($session->clientId);
            $writeState->bufferSize -= $bytesWritten;
            $session->bytesSent += $bytesWritten;
            $session->lastSendAt = $this->now;

            if ($writeState->bufferSize === 0) {
                $writeState->buffer = '';
                $writeState->bufferSize = 0;
                goto after_completed_frame_write;
            } else {
                $writeState->buffer = substr($writeState->buffer, $bytesWritten);
                goto further_write_needed;
            }
        }

        after_completed_frame_write: {
            $session->framesSent++;
            $session->messagesSent += $writeState->fin;

            if ($writeState->opcode & Frame::OP_CLOSE) {
                goto after_control_message;
            } elseif ($writeState->fin) {
                goto after_data_message;
            } else {
                goto further_write_needed;
            }
        }

        after_control_message: {
            list(, $promise) = array_shift($writeState->controlQueue);
            $promise->succeed();
            if ($writeState->opcode === Frame::OP_CLOSE) {
                goto after_close_message;
            } elseif ($writeState->dataQueue || $writeState->controlQueue) {
                goto further_write_needed;
            } else {
                goto all_data_sent;
            }
        }

        after_close_message: {
            $this->reactor->disable($session->writeWatcher);
            $session->closeState |= Session::CLOSE_SENT;

            if ($session->closeState & Session::CLOSE_DONE) {
                $this->endSession($session);
            } else {
                @stream_socket_shutdown($session->socket, STREAM_SHUT_WR);
                $this->closeTimeouts[$session->clientId] = $this->now + $this->closePeriod;
            }

            return;
        }

        after_data_message: {
            $promisor = array_shift($writeState->dataQueue)[1];
            $promisor->succeed();
            if ($writeState->dataQueue || $writeState->controlQueue) {
                goto further_write_needed;
            } else {
                goto all_data_sent;
            }
        }

        further_write_needed: {
            $this->reactor->enable($session->writeWatcher);
            return;
        }

        all_data_sent: {
            $this->reactor->disable($session->writeWatcher);
            return;
        }

        socket_gone: {
            $session->closeCode = Codes::ABNORMAL_CLOSE;
            $session->closeReason = "Socket connection severed unexpectedly";
            $this->endSession($session);
            return;
        }
    }

    /**
     *
     */
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
                $this->endSession($session);
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
            $session->writeState->controlQueue[] = [$frameStruct, null];
            $this->write($session);
        }
    }

    /**
     * @TODO Docs
     *
     * @param int $clientId
     * @param int $code
     * @param string $reason
     * @return \Amp\Promise
     */
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
        if (!($session->closeState && Session::CLOSE_INIT)) {
            $promisor = new Future($this->reactor);
            $session->closeState |= Session::CLOSE_INIT;
            $session->closePromisor = $promisor;
            $session->closecode = $code;
            $session->closeReason = isset($reason[125]) ? substr($reason, 0, 125) : $reason;
            $session->closePayload = pack('S', $code) . $reason;
            $frameStruct = $this->buildFrameStruct(Frame::OP_CLOSE, $session->closePayload, $fin=1);
            $session->writeState->controlQueue[] = [$frameStruct, $promisor];
            $this->write($session);
        } elseif ($session->closeState & Session::CLOSE_DONE) {
            $this->endSession($session);
            $promisor = $session->closePromisor;
        }

        return $promisor;
    }

    private function endSession(Session $session) {
        $this->reactor->cancel($session->readWatcher);
        $this->reactor->cancel($session->writeWatcher);

        foreach ($session->writeState->dataQueue as $framesAndPromise) {
            end($framesAndPromise)->fail(new ClientGoneException(
                'Socket connection closed'
            ));
        }

        foreach ($session->writeState->controlQueue as $framesAndPromise) {
            list(,$promise) = $framesAndPromise;
            // Only close frames have an associated promise
            if ($promise) {
                $promise->succeed();
            }
        }

        unset(
            $this->sessions[$clientId],
            $this->closeTimeouts[$clientId],
            $this->heartbeatTimeouts[$clientId]
        );

        $clientId = $session->clientId;

        $noSessionsRemaining = empty($this->sessions);

        // Don't wake up every 1000ms to execute timeouts if no clients are connected
        if ($noSessionsRemaining) {
            $this->reactor->disable($this->timeoutWatcher);
        }

        // Inform the HTTP server that we're finished with this socket. This is critically
        // important as the server *will not* release resources associated with exported
        // sockets until told to do so via this callback.
        $serverOnCloseCallback = $session->serverCloseCallback;
        $serverOnCloseCallback();

        $code = $session->closeCode;
        $reason = $session->closeReason;
        $this->notifyEndpointOnClose($clientId, $code, $reason);

        if ($this->state === Server::STOPPING && $noSessionsRemaining) {
            $this->notifyEndpointOnStop();
        }
    }

    private function notifyEndpointOnStop() {
        try {
            $result = $this->endpoint->onStop();
            if ($result instanceof \Generator) {
                return $this->resolveGenerator($result);
            } elseif ($result instanceof Promise) {
                return $result;
            } else {
                return new Success($result);
            }
        } catch (\Exception $e) {
            return new Failure($e);
        }
    }

    /**
     * @TODO Documentation
     */
    public function allowsOrigin($origin) {
        if (empty($this->allowedOrigins)) {
            return true;
        } elseif (in_array(strtolower($origin), $this->allowedOrigins)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @TODO Documentation
     */
    public function negotiateSubprotocol(array $subprotocols) {
        foreach ($subprotocols as $proto) {
            if (isset($this->subprotocols[$proto])) {
                return $proto;
            }
        }

        return false;
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
            case self::OP_ALLOWED_ORIGINS:
                $this->setAllowedOrigins($value);
                break;
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
            case self::OP_SUBPROTOCOL:
                // @TODO Do nothing right now
                break;
            case self::OP_AUTO_FRAME_SIZE:
                $this->setAutoFrameSize($value);
                break;
            case self::OP_NOTIFY_FRAMES:
                $this->setNotifyFrames($value);
                break;
            case self::OP_QUEUED_PING_LIMIT:
                $this->setQueuedPingLimit($value);
                break;
            default:
                throw new \DomainException(
                    sprintf("Unknown option: %s", $option)
                );
        }
    }

    private function setTextOnly($bool) {
        $this->textOnly = filter_var($bool, FILTER_VALIDATE_BOOLEAN);
    }

    private function setValidateUtf8($bool) {
        $this->validateUtf8 = filter_var($bool, FILTER_VALIDATE_BOOLEAN);
    }

    private function setNotifyFrames($bool) {
        $this->notifyFrames = filter_var($bool, FILTER_VALIDATE_BOOLEAN);
    }

    private function setAllowedOrigins(array $origins) {
        $this->allowedOrigins = array_map('strtolower', $origins);
    }

    private function setMaxFrameSize($bytes) {
        $this->maxFrameSize = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'default' => 2097152,
            'min_range' => 1
        ]]);
    }

    private function setMaxMsgSize($bytes) {
        $this->maxMsgSize = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'default' => 10485760,
            'min_range' => 1
        ]]);
    }

    private function setHeartbeatPeriod($seconds) {
        $this->heartbeatPeriod = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'default' => 10,
            'min_range' => 0
        ]]);
    }

    private function setAutoFrameSize($bytes) {
        $this->autoFrameSize = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'default' => 32768,
            'min_range' => 1
        ]]);
    }

    private function setClosePeriod($seconds) {
        $this->closePeriod = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'default' => 3,
            'min_range' => 1
        ]]);
    }

    private function setQueuedPingLimit($limit) {
        $this->queuedPingLimit = filter_var($limit, FILTER_VALIDATE_INT, ['options' => [
            'default' => 5,
            'min_range' => 1
        ]]);
    }
}
