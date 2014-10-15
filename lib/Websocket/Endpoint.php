<?php

namespace Aerys\Websocket;

use Amp\Reactor;
use Amp\Future;
use Amp\Resolver;
use Aerys\Server;
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
    private $app;
    private $broker;
    private $startPromisor;
    private $stopPromisor;
    private $sessions = [];
    private $closeTimeouts = [];
    private $heartbeatTimeouts = [];
    private $timeoutWatcher;
    private $now;
    private $isStopping;

    private $allowedOrigins = [];
    private $autoFrameSize = 32768;
    private $maxFrameSize = 2097152;
    private $maxMsgSize = 10485760;
    private $readGranularity = 32768;
    private $heartbeatPeriod = 10;
    private $closePeriod = 3;
    private $validateUtf8 = FALSE;
    private $textOnly = TRUE;
    private $queuedPingLimit = 3;
    private $notifyFrames = FALSE;
    // @TODO We don't currently support any subprotocols
    private $subprotocols = [];
    // @TODO add minimum average frame size rate threshold to prevent tiny-frame DoS
    private $onGeneratorResolve;

    public function __construct(Reactor $reactor, App $app, Resolver $resolver = null) {
        $this->reactor = $reactor;
        $this->app = $app;
        $this->resolver = $resolver ?: new Resolver($reactor);
        $this->broker = new Broker($this);
        $this->onGeneratorResolve = function($e, $r) { if ($e) { throw $e; } };
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
                return $this->start();
            case Server::STOPPING:
                return $this->stop();
        }
    }

    /**
     * Notify the application that the server is ready to start
     *
     * @return \Amp\Promise Returns a Promise that resolves on app startup completion
     */
    public function start() {
        if (empty($this->startPromisor)) {
            $this->startPromisor = $promisor = new Future($this->reactor);
            $promisor->when(function($e, $r) { $this->onStartCompletion($e, $r); });
            $this->notifyAppStart();
        } else {
            $promisor = $this->startPromisor;
        }

        return $promisor;
    }

    private function notifyAppStart() {
        try {
            $startResult = $this->app->start($this->broker);
            $this->startPromisor->succeed($startResult);
        } catch (\Exception $e) {
            $this->startPromisor->fail($e);
        }
    }

    private function onStartCompletion($e, $r) {
        $this->startPromisor = null;
        if (empty($e)) {
            $this->now = time();
            $this->timeoutWatcher = $this->reactor->repeat([$this, 'timeout'], $msInterval = 1000);
        }
    }

    /**
     * Stop the endpoint
     *
     * @return \Amp\Promise Returns a Promise that will resolve when all sessions are closed
     */
    public function stop() {
        if (!$this->isStopping) {
            $this->stopPromisor = new Future($this->reactor);
            $this->initializeStop();
        }

        return $this->stopPromisor->promise();
    }

    private function initializeStop() {
        if (empty($this->sessions)) {
            return $this->notifyAppStop();
        }

        $code = Codes::GOING_AWAY;
        $reason = 'Server is shutting down!';
        foreach ($this->sessions as $session) {
            $this->closeSession($session, $code, $reason);
        }

        $this->stopPromisor->when(function() {
            $this->isStopping = null;
        });
    }

    /**
     * Accepts new sockets exported by the Aerys HTTP server
     *
     * @param resource $socket A raw TCP socket stream
     * @param array $request   The HTTP request that led to this import operation
     * @param callable $closer A callback that MUST be invoked when the socket is disconnected
     *                         to notify Aerys that the client is gone.
     */
    public function import($socket, callable $closer, array $request) {
        $session = new Session;

        $socketId = (int) $socket;

        $session->id = $socketId;
        $session->socket = $socket;
        $session->closer = $closer;
        $session->stats = new SessionStats;
        $session->stats->connectedAt = $this->now;
        $session->parser = [$this, 'parseRfc6455'];
        $session->parseState = new ParseState;
        $session->writeState = new SessionWriteState;
        $session->closeState = new SessionCloseState;
        $session->readWatcher = $this->reactor->onReadable($socket, function() use ($session) {
            $this->read($session);
        });
        $session->writeWatcher = $this->reactor->onWritable($socket, function() use ($session) {
            $this->write($session);
        }, $enableNow = FALSE);

        $this->sessions[$socketId] = $session;
        $this->renewHeartbeatTimeout($socketId);
        $this->notifyAppOnOpen($socketId, $request);
    }

    private function renewHeartbeatTimeout($socketId) {
        if ($this->heartbeatPeriod > 0) {
            unset($this->heartbeatTimeouts[$socketId]);
            $this->heartbeatTimeouts[$socketId] = $this->now + $this->heartbeatPeriod;
        }
    }

    private function notifyAppOnOpen($socketId, $httpEnvironment) {
        try {
            $result = $this->app->onOpen($socketId, $httpEnvironment);
            if ($result instanceof \Generator) {
                $this->resolver->resolve($result)->when($this->onGeneratorResolve);
            }
        } catch (\Exception $e) {
            // @TODO Log error (app threw uncaught exception)
            echo $e;
        }
    }

    private function notifyAppOnData($socketId, $payload, $context) {
        try {
            $result = $this->app->onData($socketId, $payload, $context);
            if ($result instanceof \Generator) {
                $this->resolver->resolve($result)->when($this->onGeneratorResolve);
            }
        } catch (\Exception $e) {
            // @TODO Log error (app threw uncaught exception)
            echo $e;
        }
    }

    private function notifyAppOnClose($socketId, $code, $reason) {
        try {
            $result = $this->app->onClose($socketId, $code, $reason);
            if ($result instanceof \Generator) {
                $this->resolver->resolve($result)->when($this->onGeneratorResolve);
            }
        } catch (\Exception $e) {
            // @TODO Log error (app threw uncaught exception)
            echo $e;
        }
    }

    /**
     * @TODO Docs
     */
    public function stats($socketId) {
        if (isset($this->sessions[$socketId])) {
            $session = $this->sessions[$socketId];
            return clone $session->stats;
        } else {
            throw new \DomainException;
        }
    }

    /**
     * Push the specified Data object out to its recipients
     *
     * @param string $payload
     * @param array $recipients
     */
    public function send($payload, array $recipients) {
        $recipients = empty($recipients)
            ? $this->sessions
            : array_intersect_key($this->sessions, array_flip($recipients));

        if (empty($recipients)) {
            return;
        }

        $opcode = preg_match('//u', $payload) ? Frame::OP_TEXT : Frame::OP_BIN;

        $frameStructs = $this->generateDataFrameStructs($opcode, $payload);

        foreach ($recipients as $session) {
            foreach ($frameStructs as $frameStruct) {
                $session->writeState->dataQueue[] = $frameStruct;
            }
            $this->write($session);
        }
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
            // Yes, this limits payloads to 2.1GB ...
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

        if ($data || $data === '0') {
            $dataLen = strlen($data);
            $session->parseState->buffer .= $data;

            $session->parseState->bufferSize += $dataLen;
            $session->stats->lastReadAt = $this->now;
            $session->stats->bytesRead += $dataLen;
            $this->renewHeartbeatTimeout($session->id);
            $this->parse($session);
        } elseif (!is_resource($session->socket) || @feof($session->socket)) {
            $closeState = $session->closeState;
            $closeState->code = Codes::ABNORMAL_CLOSE;
            $closeState->reason = "Socket connection severed unexpectedly";
            $this->endSession($session);
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
            $session->closeState->code = Codes::PROTOCOL_ERROR;
            $session->closeState->reason = $e->getMessage();
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

        $session->stats->framesRead++;
        $session->stats->messagesRead += $fin;

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
                $this->receiveData($session, $payload, $opcode, $fin);
        }
    }

    private function receiveData(Session $session, $payload, $opcode, $fin) {
        $session->messageBuffer .= $payload;
        if ($fin || $this->notifyFrames) {
            $payload = $session->messageBuffer;
            $session->messageBuffer = '';
            $this->notifyAppOnData($session->id, $payload, $context = [
                'opcode' => $opcode,
                'fin' => $fin
            ]);
        }
    }

    private function receivePing(Session $session, $payload) {
        $frameStruct = $this->buildFrameStruct(Frame::OP_PONG, $payload, $fin=1);
        $session->writeState->controlQueue[] = $frameStruct;
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
        $session->closeState->state |= SessionCloseState::RECD;

        if ($session->closeState->state & SessionCloseState::DONE) {
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
        $stats = $session->stats;
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
            list($writeState->buffer, $writeState->opcode, $writeState->fin) = $queue[$key];
            $writeState->bufferSize = strlen($writeState->buffer);
            unset($queue[$key]);
            goto write;
        }

        write: {
            if ($bytesWritten = @fwrite($session->socket, $writeState->buffer)) {
                goto renew_heartbeat;
            } else {
                goto after_empty_write;
            }
        }

        renew_heartbeat: {
            $this->renewHeartbeatTimeout($session->id);
            goto after_write;
        }

        after_write: {
            $writeState->bufferSize -= $bytesWritten;
            $stats->bytesSent += $bytesWritten;
            $stats->lastSendAt = $this->now;

            if ($writeState->bufferSize === 0) {
                $writeState->buffer = '';
                $writeState->bufferSize = 0;
                goto after_completed_frame_write;
            } else {
                $writeState->buffer = substr($writeState->buffer, $bytesWritten);
                goto further_write_needed;
            }
        }

        after_empty_write: {
            if (is_resource($session->socket)) {
                goto further_write_needed;
            } else {
                goto socket_gone;
            }
        }

        after_completed_frame_write: {
            $stats->framesSent++;
            $stats->messagesSent += $writeState->fin;

            if ($writeState->opcode === Frame::OP_CLOSE) {
                goto after_close_frame;
            // @TODO handle PING/PONG here too!
            } elseif ($writeState->dataQueue || $writeState->controlQueue) {
                goto further_write_needed;
            } else {
                goto all_data_sent;
            }
        }

        after_close_frame: {
            $this->reactor->disable($session->writeWatcher);
            $closeState = $session->closeState;
            $closeState->state |= SessionCloseState::SENT;

            if ($closeState->state & SessionCloseState::DONE) {
                $this->endSession($session);
            } else {
                @stream_socket_shutdown($session->socket, STREAM_SHUT_WR);
                $this->closeTimeouts[$session->id] = $this->now + $this->closePeriod;
            }

            return;
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
            $closeState = $session->closeState;
            $closeState->code = Codes::ABNORMAL_CLOSE;
            $closeState->reason = "Socket connection severed unexpectedly";
            $this->endSession($session);
            return;
        }
    }

    /**
     *
     */
    public function timeout() {
        $this->now = $now = time();

        foreach ($this->heartbeatTimeouts as $socketId => $expiryTime) {
            if ($expiryTime < $now) {
                $session = $this->sessions[$socketId];
                unset($this->heartbeatTimeouts[$socketId]);
                $this->heartbeatTimeouts[$socketId] = $now;
                $this->sendHeartbeatPing($session);
            } else {
                break;
            }
        }

        foreach ($this->closeTimeouts as $socketId => $expiryTime) {
            if ($expiryTime < $now) {
                $session = $this->sessions[$socketId];
                $session->closeState->code = Codes::ABNORMAL_CLOSE;
                $session->closeState->reason = 'CLOSE handshake timeout';
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
            $session->writeState->controlQueue[] = $frameStruct;
            $this->write($session);
        }
    }

    /**
     * @TODO Docs
     */
    public function close($socketId, $code = Codes::NORMAL_CLOSE, $reason = '') {
        if (!isset($this->sessions[$socketId])) {
            return;
        }

        $code = filter_var($code, FILTER_VALIDATE_INT, ['options' => [
            'default' => Codes::NONE,
            'min_range' => 1000,
            'max_range' => 4999
        ]]);

        $reason = (string) $reason;
        if (isset($reason[125])) {
            $reason = substr($reason, 0, 125);
        }

        $session = $this->sessions[$socketId];
        $this->closeSession($session, $code, $reason);
    }

    private function closeSession(Session $session, $code, $reason = '') {
        $closeState = $session->closeState;

        if (!($closeState->state & SessionCloseState::INIT)) {
            $closeState->state |= SessionCloseState::INIT;
            $closeState->code = $code;
            $closeState->reason = isset($reason[125]) ? substr($reason, 0, 125) : $reason;
            $closeState->payload = pack('S', $code) . $reason;
            $frameStruct = $this->buildFrameStruct(Frame::OP_CLOSE, $closeState->payload, $fin=1);
            $session->writeState->controlQueue[] = $frameStruct;
            $this->write($session);
        }

        if ($closeState->state & SessionCloseState::DONE) {
            $this->endSession($session);
        }
    }

    private function endSession(Session $session) {
        $this->reactor->cancel($session->readWatcher);
        $this->reactor->cancel($session->writeWatcher);

        $socketId = $session->id;

        unset(
            $this->sessions[$socketId],
            $this->closeTimeouts[$socketId],
            $this->heartbeatTimeouts[$socketId]
        );

        $noSessionsRemaining = empty($this->sessions);

        // Don't wakeup every second to execute timeouts if no clients are connected
        if ($noSessionsRemaining) {
            $this->reactor->cancel($this->timeoutWatcher);
        }

        // Inform the HTTP server that we're finished with this socket
        call_user_func($session->closer);

        $code = $session->closeState->code;
        $reason = $session->closeState->reason;
        $this->notifyAppOnClose($socketId, $code, $reason);

        if ($this->isStopping && empty($this->sessions)) {
            $this->notifyAppStop();
        }
    }

    private function notifyAppStop() {
        try {
            $result = $this->app->stop();
            $this->stopPromisor->resolveSafely(null, $result);
        } catch (\Exception $e) {
            $this->stopPromisor->resolveSafely(null, $e);
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
    public function allowsOrigin($origin) {
        if (empty($this->allowedOrigins)) {
            return TRUE;
        } elseif (in_array(strtolower($origin), $this->allowedOrigins)) {
            return TRUE;
        } else {
            return FALSE;
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

        return FALSE;
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
