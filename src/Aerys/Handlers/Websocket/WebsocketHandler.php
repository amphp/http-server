<?php

namespace Aerys\Handlers\Websocket;

use Amp\Reactor,
    Aerys\Status,
    Aerys\Reason,
    Aerys\Method,
    Aerys\Server;

class WebsocketHandler implements \Countable {
    
    const ACCEPT_CONCAT = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    
    private $reactor;
    private $server;
    private $frameStreamFactory;
    private $sessions = [];
    private $endpoints = [];
    
    private $socketReadTimeout = 30;
    private $queuedPingLimit = 3;
    private $closeResponseTimeout = 5;
    private $autoFrameSize = 65365;
    private $socketReadGranularity = 65365;
    private $defaultEndpointOptions = [
        'subprotocol'      => NULL,
        'allowedOrigins'   => [],
        'maxFrameSize'     => 2097152,
        'maxMsgSize'       => 10485760,
        'heartbeatPeriod'  => 10
        // @TODO add minimum average frame size rate threshold to prevent really-small-frame DoS
    ];
    
    function __construct(Reactor $reactor, Server $server, FrameStreamFactory $frameStreamFactory = NULL) {
        $this->reactor = $reactor;
        $this->server = $server;
        $this->frameStreamFactory = $frameStreamFactory ?: new FrameStreamFactory;
    }
    
    /**
     * Send UTF-8 text to one or more connected sockets
     * 
     * @param mixed $socketIdOrArray A socket ID or array of socket IDs
     * @param string $data The data to be sent
     * @param callable $afterSend An optional callback to invoke after the non-blocking send completes
     */
    function sendText($socketIdOrArray, $data, callable $afterSend = NULL) {
        $this->broadcast($socketIdOrArray, Frame::OP_TEXT, $data, $afterSend);
    }
    
    /**
     * Send binary data to one or more connected sockets
     * 
     * @param mixed $socketIdOrArray A socket ID or array of socket IDs
     * @param string $data The data to be sent
     * @param callable $afterSend An optional callback to invoke after the non-blocking send completes
     */
    function sendBinary($socketIdOrArray, $data, callable $afterSend = NULL) {
        $this->broadcast($socketIdOrArray, Frame::OP_BIN, $data, $afterSend);
    }
    
    /**
     * Retrieve the ASGI request environment used to originate the user's websocket connection
     * 
     * @param int $socketId
     * @throws \DomainException On unknown socket ID
     * @return array The ASGI request environment used to initiate the specified ID's websocket session
     */
    function getEnvironment($socketId) {
        if (isset($this->sessions[$socketId])) {
            $session = $this->sessions[$socketId];
            return $session->asgiEnv;
        } else {
            throw new \DomainException(
                "Unknown socket ID: {$socketId}"
            );
        }
    }
    
    /**
     * Retrieve aggregate IO statistics for the specified socket ID
     * 
     * @param int $socketId
     * @throws \DomainException On unknown socket ID
     * @return array A map of numeric stats for the specified socket ID
     */
    function getStats($socketId) {
        if (isset($this->sessions[$socketId])) {
            $session = $this->sessions[$socketId];
        } else {
            throw new \DomainException(
                "Unknown socket ID: {$socketId}"
            );
        }
        
        return [
            'dataBytesRead'     => $session->dataBytesRead,
            'dataBytesSent'     => $session->dataBytesSent,
            'dataFramesRead'    => $session->dataFramesRead,
            'dataFramesSent'    => $session->dataFramesSent,
            'dataMessagesRead'  => $session->dataMessagesRead,
            'dataMessagesSent'  => $session->dataMessagesSent,
            'controlBytesRead'  => $session->controlBytesRead,
            'controlBytesSent'  => $session->controlBytesSent,
            'controlFramesRead' => $session->controlFramesRead,
            'controlFramesSent' => $session->controlFramesSent,
            'dataLastReadAt'    => $session->dataLastReadAt,
            'connectedAt'       => $session->connectedAt
        ];
    }
    
    /**
     * How many total websocket connections are currently open?
     * 
     * @return int
     */
    function count() {
        return count($this->sessions);
    }
    
    function registerEndpoint($requestUri, Endpoint $endpoint, array $options = []) {
        if ($requestUri[0] !== '/') {
            throw new \InvalidArgumentException(
                'Endpoint URI must begin with a backslash /'
            );
        }
        
        $options = $options ? $this->normalizeEndpointOptions($options) : $this->defaultEndpointOptions;
        
        $this->endpoints[$requestUri] = [$endpoint, $options];
    }
    
    private function normalizeEndpointOptions(array $userOptions) {
        $mergedOptions = array_merge($this->defaultEndpointOptions, $userOptions);
        $opts = array_intersect_key($mergedOptions, $this->defaultEndpointOptions);
        
        $opts['subprotocol'] = $this->normalizeSubprotocol($opts['subprotocol']);
        $opts['allowedOrigins'] = $this->normalizeAllowedOrigins($opts['allowedOrigins']);
        $opts['maxFrameSize'] = $this->normalizeMaxFrameSize($opts['maxFrameSize']);
        $opts['maxMsgSize'] = $this->normalizeMaxMsgSize($opts['maxMsgSize']);
        $opts['heartbeatPeriod'] = $this->normalizeHeartbeatPeriod($opts['heartbeatPeriod']);
        
        return $opts;
    }
    
    private function normalizeSubprotocol($subprotocol) {
        return (string) $subprotocol;
    }
    
    private function normalizeAllowedOrigins(array $origins) {
        return array_map('strtolower', $origins);
    }
    
    private function normalizeMaxFrameSize($bytes) {
        return filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'default' => 2097152,
            'min_range' => 1
        ]]);
    }
    
    private function normalizeMaxMsgSize($bytes) {
        return filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'default' => 10485760,
            'min_range' => 1
        ]]);
    }
    
    private function normalizeHeartbeatPeriod($seconds) {
        return filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'default' => 10,
            'min_range' => 0
        ]]);
    }
    
    /**
     * Determine from an ASGI request environment whether a request can be upgraded to websocket
     * 
     * @param array $asgiEnv The HTTP request environment
     * @return array Returns an ASGI response array
     */
    function __invoke(array $asgiEnv) {
        list($isAccepted, $handshakeResult) = $this->validateClientHandshake($asgiEnv);
        
        if ($isAccepted) {
            list($version, $protocol, $extensions) = $handshakeResult;
            $response = $this->generateServerHandshake($asgiEnv, $version, $protocol, $extensions);
        } else {
            $response = $handshakeResult;
        }
        
        return $response;
    }
    
    private function validateClientHandshake(array $asgiEnv) {
        $requestUri = $asgiEnv['REQUEST_URI'];
        $queryString = $asgiEnv['QUERY_STRING'];
        if ($queryString || $queryString === '0') {
            $requestUri = str_replace("?{$queryString}", '', $requestUri);
        }
        
        if (isset($this->endpoints[$requestUri])) {
            $endpointOptions = $this->endpoints[$requestUri][1];
        } else {
            return [FALSE, [Status::NOT_FOUND, Reason::HTTP_404, [], NULL]];
        }
        
        if ($asgiEnv['REQUEST_METHOD'] != 'GET') {
            return [FALSE, [Status::METHOD_NOT_ALLOWED, Reason::HTTP_405, [], NULL]];
        }
        
        if ($asgiEnv['SERVER_PROTOCOL'] < 1.1) {
            return [FALSE, [Status::HTTP_VERSION_NOT_SUPPORTED, Reason::HTTP_505, [], NULL]];
        }
        
        if (empty($asgiEnv['HTTP_UPGRADE']) || strcasecmp($asgiEnv['HTTP_UPGRADE'], 'websocket')) {
            return [FALSE, [Status::UPGRADE_REQUIRED, Reason::HTTP_426, [], NULL]];
        }
        
        if (empty($asgiEnv['HTTP_CONNECTION']) || !$this->validateConnectionHeader($asgiEnv['HTTP_CONNECTION'])) {
            $reason = 'Bad Request: "Connection: Upgrade" header required';
            return [FALSE, [Status::BAD_REQUEST, $reason, [], NULL]];
        }
        
        if (empty($asgiEnv['HTTP_SEC_WEBSOCKET_KEY'])) {
            $reason = 'Bad Request: "Sec-Websocket-Key" header required';
            return [FALSE, [Status::BAD_REQUEST, $reason, [], NULL]];
        }
        
        if (empty($asgiEnv['HTTP_SEC_WEBSOCKET_VERSION'])) {
            $reason = 'Bad Request: "Sec-WebSocket-Version" header required';
            return [FALSE, [Status::BAD_REQUEST, $reason, [], NULL]];
        }
        
        $version = NULL;
        $requestedVersions = explode(',', $asgiEnv['HTTP_SEC_WEBSOCKET_VERSION']);
        foreach ($requestedVersions as $requestedVersion) {
            if ($requestedVersion === '13') {
                $version = 13;
                break;
            }
        }
        
        if (!$version) {
            $reason = 'Bad Request: Requested WebSocket version(s) unavailable';
            $headers = ['Sec-WebSocket-Version' => 13];
            return [FALSE, [Status::BAD_REQUEST, $reason, $headers, NULL]];
        }
        
        $allowedOrigins = $endpointOptions['allowedOrigins'];
        $originHeader = empty($asgiEnv['HTTP_ORIGIN']) ? NULL : $asgiEnv['HTTP_ORIGIN'];
        if ($allowedOrigins && !in_array($originHeader, $allowedOrigins)) {
            return [FALSE, [Status::FORBIDDEN, Reason::HTTP_403, [], NULL]];
        }
        
        $subprotocol = $endpointOptions['subprotocol'];
        $subprotocolHeader = !empty($asgiEnv['HTTP_SEC_WEBSOCKET_PROTOCOL'])
            ? explode(',', $asgiEnv['HTTP_SEC_WEBSOCKET_PROTOCOL'])
            : [];
        
        if ($subprotocol && !in_array($subprotocol, $subprotocolHeader)) {
            $reason = 'Bad Request: Requested WebSocket subprotocol(s) unavailable';
            return [FALSE, [Status::BAD_REQUEST, $reason, [], NULL]];
        }
        
        /**
         * @TODO Negotiate supported Sec-WebSocket-Extensions
         * 
         * The Sec-WebSocket-Extensions header field is used to select protocol-level extensions as
         * outlined in RFC 6455 Section 9.1:
         * 
         * http://tools.ietf.org/html/rfc6455#section-9.1
         * 
         * As of 2013-03-08 no extensions have been registered with the IANA:
         * 
         * http://www.iana.org/assignments/websocket/websocket.xml#extension-name
         */
        $extensions = [];
        
        return [TRUE, [$version, $subprotocol, $extensions]];
    }
    
    /**
     * Some browsers send multiple connection headers e.g. `Connection: keep-alive, Upgrade` so it's
     * necessary to check for the upgrade value as part of a comma-delimited list.
     */
    private function validateConnectionHeader($header) {
        $hasConnectionUpgrade = FALSE;
        
        if (!strcasecmp($header, 'upgrade')) {
            $hasConnectionUpgrade = TRUE;
        } elseif (strstr($header, ',')) {
            $parts = explode(',', $header);
            foreach ($parts as $part) {
                if (!strcasecmp(trim($part), 'upgrade')) {
                    $hasConnectionUpgrade = TRUE;
                    break;
                }
            }
        }
        
        return $hasConnectionUpgrade;
    }
    
    private function generateServerHandshake(array $asgiEnv, $version, $subprotocol, $extensions) {
        $concatenatedKeyStr = $asgiEnv['HTTP_SEC_WEBSOCKET_KEY'] . self::ACCEPT_CONCAT;
        $secWebSocketAccept = base64_encode(sha1($concatenatedKeyStr, TRUE));
        
        $headers = [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => $secWebSocketAccept
        ];
        
        if ($subprotocol || $subprotocol === '0') {
            $headers['Sec-WebSocket-Protocol'] = $subprotocol;
        }
        
        if ($extensions) {
            $headers['Sec-WebSocket-Extensions'] = implode(',', $extensions);
        }
        
        return [
            Status::SWITCHING_PROTOCOLS,
            Reason::HTTP_101,
            $headers,
            $body = NULL,
            [$this, 'importSocket']
        ];
    }
    
    function importSocket($socket, array $asgiEnv) {
        $socketId = (int) $socket;
        
        $requestUri = $asgiEnv['REQUEST_URI'];
        
        if (($queryString = $asgiEnv['QUERY_STRING']) || $queryString === '0') {
            $requestUri = str_replace("?{$queryString}", '', $requestUri);
        }
        
        list($endpoint, $endpointOptions) = $this->endpoints[$requestUri];
        
        $session = new Session;
        
        $session->id = $socketId;
        $session->socket = $socket;
        $session->asgiEnv = $asgiEnv;
        $session->connectedAt = time();
        $session->endpoint = $endpoint;
        $session->endpointOptions = $endpointOptions;
        $session->endpointUri = $requestUri;
        $session->frameWriter = new FrameWriter($socket);
        $session->frameParser = (new FrameParser)->setOptions([
            'maxFrameSize' => $session->endpointOptions['maxFrameSize'],
            'maxMsgSize' => $session->endpointOptions['maxMsgSize']
        ]);
        
        $readTimeout = $session->endpointOptions['heartbeatPeriod'] ?: $this->socketReadTimeout;
        $onReadable = function($socket, $trigger) use ($session) { $this->read($session, $trigger); };
        $session->readSubscription = $this->reactor->onReadable($socket, $onReadable, $readTimeout);
        
        $this->sessions[$socketId] = $session;
        
        $this->onOpen($session);
    }
    
    private function onOpen(Session $session) {
        try {
            $session->endpoint->onOpen($session->id);
        } catch (\Exception $e) {
            $this->onEndpointError($session, $e);
        }
    }
    
    private function read(Session $session, $trigger) {
        if ($trigger === Reactor::READ) {
            $this->doSocketRead($session);
        } elseif ($session->endpointOptions['heartbeatPeriod']) {
            $this->doHeartbeat($session);
        }
    }
    
    /**
     * At the time of this writing some browsers (I'm looking at you, Chrome) will not respond
     * to PING frames that don't carry application data in the frame payload. To correct for this,
     * we ensure there is always a payload attached to each outbound PING frame.
     * 
     * @link http://tools.ietf.org/html/rfc6455#section-5.5.2
     */
    private function doHeartbeat(Session $session) {
        $data = pack('S', rand(0, 32768));
        $pingFrame = new Frame(Frame::FIN, Frame::RSV_NONE, Frame::OP_PING, $data);
        $session->frameWriter->enqueue($pingFrame);
        $this->doSessionWrite($session);
    }
    
    private function doSocketRead(Session $session) {
        $data = @fread($session->socket, $this->socketReadGranularity);
        
        if ($data || $data === '0') {
            $session->dataLastReadAt = time();
            $this->parseSocketData($session, $data);
        } elseif (!is_resource($session->socket) || @feof($session->socket)) {
            $this->onClose($session, Codes::ABNORMAL_CLOSE, 'Client went away');
        }
    }
    
    private function parseSocketData(Session $session, $data) {
        try {
            while ($parsedMsgArr = $session->frameParser->parse($data)) {
                $this->onParsedMessage($session, $parsedMsgArr);
                $data = '';
            }
        } catch (PolicyException $e) {
            $this->close($session, Codes::POLICY_VIOLATION, $e->getMessage());
        } catch (ParseException $e) {
            $code = Codes::PROTOCOL_ERROR;
            $reason = $e->getMessage();
            if ($session->closeState) {
                $this->onClose($session, $code, $reason);
            } else {
                $this->close($session, $code, $reason);
            }
        }
    }
    
    private function onParsedMessage(Session $session, array $parsedMsgArr) {
        list($opcode, $payload, $length, $frames) = $parsedMsgArr;
        
        if ($opcode < Frame::OP_CLOSE) {
            $session->dataMessagesRead++;
            foreach ($frames as $frameStruct) {
                $session->dataBytesRead += $frameStruct['length'];
                $session->dataFramesRead++;
            }
            $msg = new Message($opcode, $payload, $length);
            $this->onMessage($session, $msg);
        } else {
            $session->controlFramesRead++;
            $session->controlBytesRead += $length;
            $this->afterControlFrameRead($session, $opcode, $payload);
        }
    }
    
    private function onMessage(Session $session, Message $msg) {
        try {
            $session->endpoint->onMessage($session->id, $msg);
        } catch (\Exception $e) {
            $this->onEndpointError($session, $e);
        }
    }
    
    private function afterControlFrameRead(Session $session, $opcode, $payload) {
        switch ($opcode) {
            case Frame::OP_PING:
                $this->afterPingFrameRead($session, $payload);
                break;
            case Frame::OP_PONG:
                $this->afterPongFrameRead($session, $payload);
                break;
            case Frame::OP_CLOSE:
                $this->afterCloseFrameRead($session, $payload);
                break;
        }
    }
    
    private function afterPingFrameRead(Session $session, $payload) {
        $pongFrame = new Frame(Frame::FIN, Frame::RSV_NONE, Frame::OP_PONG, $payload);
        $session->frameWriter->enqueue($pongFrame);
        $this->doSessionWrite($session);
    }
    
    private function afterPongFrameRead(Session $session, $payload) {
        for ($i=count($session->pendingPingPayloads)-1; $i>=0; $i--) {
            if ($session->pendingPingPayloads[$i] == $payload) {
                $session->pendingPingPayloads = array_slice($session->pendingPingPayloads, $i+1);
                break;
            }
        }
    }
    
    private function afterCloseFrameRead(Session $session, $payload) {
        $session->closeState |= Session::CLOSE_FRAME_RECEIVED;
        
        if ($session->closeState & Session::CLOSE_HANDSHAKE_COMPLETE) {
            $this->onClose($session, $session->pendingCloseCode, $session->pendingCloseReason);
        } else {
            @stream_socket_shutdown($session->socket, STREAM_SHUT_RD);
            list($code, $reason) = $this->parseCloseFramePayload($payload);
            $this->close($session, $code, $reason);
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
    
    private function broadcast($recipients, $opcode, $data, callable $afterSend = NULL) {
        try {
            $frameStream = $this->frameStreamFactory->__invoke($opcode, $data);
            $frameStream->setFrameSize($this->autoFrameSize);
            $recipients = is_array($recipients) ? $recipients : [$recipients];
            
            foreach ($recipients as $socketId) {
                $session = $this->sessions[$socketId];
                $session->frameStreamQueue[] = [$frameStream, $afterSend];
                $this->enqueueNextDataFrame($session);
                $this->doSessionWrite($session);
            }
            
        } catch (\InvalidArgumentException $e) {
            $this->onEndpointError($session, $e);
        }
    }
    
    function close($recipients, $code, $reason) {
        $payload = pack('n', $code) . substr($reason, 0, 125);
        $frame = new Frame(Frame::FIN, Frame::RSV_NONE, Frame::OP_CLOSE, $payload);
        
        $recipients = is_array($recipients) ? $recipients : [$recipients];
        
        foreach ($recipients as $socketId) {
            $session = $this->sessions[$socketId];
            $session->pendingCloseCode = $code;
            $session->pendingCloseReason = $reason;
            $session->frameWriter->enqueue($frame);
            $this->doSessionWrite($session);
        }
    }
    
    private function doSessionWrite(Session $session) {
        try {
            if ($session->closeState & Session::CLOSE_FRAME_SENT) {
                $isWritingComplete = TRUE;
            } elseif ($frame = $session->frameWriter->write()) {
                $isWritingComplete = $this->afterFrameWrite($session, $frame);
            } else {
                $isWritingComplete = FALSE;
            }
            
            if ($isWritingComplete) {
                $this->disableWriteSubscription($session);
            } else {
                $this->enableWriteSubscription($session);
            }
        } catch (FrameWriteException $e) {
            $this->onWriteFailure($session, $e);
        } catch (EndpointException $e) {
            $this->onEndpointError($session, $e);
        }
    }
    
    private function afterFrameWrite(Session $session, Frame $frame) {
        switch ($frame->getOpcode()) {
            case Frame::OP_TEXT:
                $isWritingComplete = $this->afterDataFrameWrite($session, $frame);
                break;
            case Frame::OP_BIN:
                $isWritingComplete = $this->afterDataFrameWrite($session, $frame);
                break;
            case Frame::OP_CONT:
                $isWritingComplete = $this->afterDataFrameWrite($session, $frame);
                break;
            case Frame::OP_PING:
                $isWritingComplete = $this->afterPingFrameWrite($session, $frame);
                break;
            case Frame::OP_PONG:
                $isWritingComplete = $this->afterPongFrameWrite($session, $frame);
                break;
            case Frame::OP_CLOSE:
                $isWritingComplete = $this->afterCloseFrameWrite($session, $frame);
                break;
            default:
                throw new \UnexpectedValueException;
        }
        
        return $isWritingComplete;
    }
    
    private function afterCloseFrameWrite(Session $session, Frame $frame) {
        $session->controlFramesSent++;
        $session->controlBytesSent += $frame->getLength();
        $session->closeState |= Session::CLOSE_FRAME_SENT;
        
        if ($session->closeState & Session::CLOSE_HANDSHAKE_COMPLETE) {
            $this->onClose($session, $session->pendingCloseCode, $session->pendingCloseReason);
        } else {
            @stream_socket_shutdown($session->socket, STREAM_SHUT_WR);
            $session->closeTimeoutSubscription = $this->reactor->once(function() use ($session) {
                $code = Codes::POLICY_VIOLATION;
                $reason = 'CLOSE response not received from client within the allowed time period';
                $this->onClose($session, $code, $reason);
            }, $this->closeResponseTimeout);
        }
        
        return $isWritingComplete = TRUE;
    }
    
    private function afterPingFrameWrite(Session $session, Frame $frame) {
        $session->controlFramesSent++;
        $session->controlBytesSent += $frame->getLength();
        
        // Punish naughty clients who don't respond to PINGs; we can't store these payloads forever.
        if (array_push($session->pendingPingPayloads, $frame->getPayload()) > $this->queuedPingLimit) {
            $code = Codes::POLICY_VIOLATION;
            $reason = 'Exceeded unanswered PING limit';
            $this->close($session, $code, $reason);
        }
        
        return ($isWritingComplete = !$session->frameWriter->canWrite());
    }
    
    private function afterPongFrameWrite(Session $session, Frame $frame) {
        $session->controlFramesSent++;
        $session->controlBytesSent += $frame->getLength();
        
        return ($isWritingComplete = !$session->frameWriter->canWrite());
    }
    
    private function afterDataFrameWrite(Session $session, Frame $frame) {
        $session->dataFramesSent++;
        $session->dataBytesSent += $frame->getLength();
        
        $wasLastFrameInMsg = $frame->isFin();
        
        if ($wasLastFrameInMsg) {
            $session->dataMessagesSent++;
            $this->afterMessageSend($session);
        }
                
        if ($session->frameStream || $session->frameStreamQueue) {
            $this->enqueueNextDataFrame($session);
            $isWritingComplete = FALSE;
        } else {
            $isWritingComplete = TRUE;
        }
        
        return $isWritingComplete;
    }
    
    private function afterMessageSend(Session $session) {
        try {
            if ($callback = $session->afterMessageSend) {
                $session->afterMessageSend = NULL;
                $callback($session->id);
            }
        } catch (\Exception $userlandException) {
            throw new EndpointException(
                'afterMessage callback threw :(',
                $errorCode = 0,
                $userlandException
            );
        }
    }
    
    private function enqueueNextDataFrame(Session $session) {
        if (!$session->frameStream) {
            list($nextStream, $afterSend) = array_shift($session->frameStreamQueue);
            
            // This rewind() call is not an accident. If the same frame stream is being sent to
            // multiple clients the stream may not be at the start position. We need to rewind it
            // in the same way we seek on preexisting frame streams.
            $nextStream->rewind();
            
            $session->frameStream = $nextStream;
            $session->afterMessageSend = $afterSend;
        } else {
            $session->frameStream->seek($session->frameStreamPosition);
        }
        
        try {
            $opcode = $session->frameStream->isBinary() ? Frame::OP_BIN : Frame::OP_TEXT;
            $payload = $session->frameStream->current();
            
            $session->frameStream->next();
            $session->frameStreamPosition = $session->frameStream->key();
            
            if ($isFin = !$session->frameStream->valid()) {
                $session->frameStream = $session->frameStreamPosition = NULL;
            }
            
            $frame = new Frame($isFin, Frame::RSV_NONE, $opcode, $payload);
            $session->frameWriter->enqueue($frame);
            
        } catch (\Exception $e) {
            @fwrite($session->asgiEnv['ASGI_ERROR'], $e);
            
            $code = Codes::UNEXPECTED_SERVER_ERROR;
            $reason = 'Resource read failure :(';
            $this->close($session, $code, $reason);
        }
    }
    
    private function onWriteFailure(Session $session, FrameWriteException $e) {
        $frame = $e->getFrame();
        $bytesCompleted = $e->getBytesCompleted();
        $isControlFrame = ($frame->getOpcode() >= Frame::OP_CLOSE);
        
        if ($isControlFrame) {
            $session->controlBytesSent += $bytesCompleted;
        } else {
            $session->dataBytesSent += $bytesCompleted;
        }
        
        $code = Codes::ABNORMAL_CLOSE;
        $reason = 'Connection severed unexpectedly';
        $this->onClose($session, $code, $reason);
    }
    
    private function onEndpointError(Session $session, $e) {
        @fwrite($session->asgiEnv['ASGI_ERROR'], $e);
        
        $code = Codes::UNEXPECTED_SERVER_ERROR;
        $reason = 'Unexpected internal error :(';
        $this->close($session, $code, $reason);
    }
    
    private function onClose(Session $session, $code, $reason) {
        try {
            $this->unloadSession($session);
            $session->endpoint->onClose($session->id, $code, $reason);
        } catch (\Exception $e) {
            // The client has already disconnected so the only thing to do here is log the error
            @fwrite($session->asgiEnv['ASGI_ERROR'], $e);
        }
    }
    
    private function unloadSession(Session $session) {
        $this->server->closeExportedSocket($session->socket);
        
        $session->readSubscription->cancel();
        if ($session->writeSubscription) {
            $session->writeSubscription->cancel();
        }
        
        if ($session->closeTimeoutSubscription) {
            $session->closeTimeoutSubscription->cancel();
        }
        
        unset($this->sessions[$session->id]);
    }
    
    private function enableWriteSubscription(Session $session) {
        if ($session->writeSubscription) {
            $session->writeSubscription->enable();
        } else {
            $subscription = $this->reactor->onWritable($session->socket, function() use ($session) {
                $this->doSessionWrite($session);
            });
            $session->writeSubscription = $subscription;
        }
    }
    
    private function disableWriteSubscription(Session $session) {
        if ($session->writeSubscription) {
            $session->writeSubscription->disable();
        }
    }
    
}
