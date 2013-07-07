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
    private $frameStreamFactory;
    private $sessions;
    private $endpoints = [];
    private $endpointClientMap = [];
    private $socketReadTimeout = 30;
    private $queuedPingLimit = 3;
    private $closeResponseTimeout = 5;
    private $autoFrameSize = 65365;
    private $socketReadGranularity = 65365;
    private $defaultEndpointOptions = [
        'subprotocol'      => NULL,
        'allowedOrigins'   => [], // <-- empty array means all origins are allowed
        'msgSwapSize'      => 2097152,
        'maxFrameSize'     => 2097152,
        // @TODO add minimum average frame size threshold to prevent single-byte-per-frame DoS traffic
        'maxMsgSize'       => 10485760,
        'heartbeatPeriod'  => 10
    ];
    
    function __construct(Reactor $reactor, array $endpoints, FrameStreamFactory $frameStreamFactory = NULL) {
        $this->reactor = $reactor;
        $this->frameStreamFactory = $frameStreamFactory ?: new FrameStreamFactory;
        $this->sessions = new \SplObjectStorage;
        
        $this->setEndpoints($endpoints);
    }
    
    private function setEndpoints(array $endpoints) {
        if (empty($endpoints)) {
             throw new \InvalidArgumentException(
                'Endpoint array must not be empty'
            );
        }
        
        foreach ($endpoints as $requestUri => $endpoint) {
            if ($endpoint instanceof Endpoint) {
                $endpointOptions = $this->normalizeEndpointOptions($endpoint);
                $this->endpoints[$requestUri] = [$endpoint, $endpointOptions];
                $this->endpointClientMap[$requestUri] = [];
            } else {
                throw new \InvalidArgumentException(
                    'Endpoint instance expected at index: ' . $requestUri
                );
            }
        }
    }
    
    private function normalizeEndpointOptions(Endpoint $endpoint) {
        $userOptions = $endpoint->getOptions();
        
        if (!$userOptions) {
            $endpointOptions = $this->defaultEndpointOptions;
        } elseif (is_array($userOptions)) {
            $mergedOptions = array_merge($this->defaultEndpointOptions, $userOptions);
            $endpointOptions = array_intersect_key($mergedOptions, $this->defaultEndpointOptions);
            foreach ($endpointOptions as $key => $value) {
                $normalizer = 'normalize' . ucfirst($key);
                $endpointOptions[$key] = $this->$normalizer($value);
            }
        } else {
            throw new \UnexpectedValueException(
                'Endpoint::getOptions() must return an array'
            );
        }
        
        return $endpointOptions;
    }
    
    private function normalizeSubprotocol($subprotocol) {
        return (string) $subprotocol;
    }
    
    private function normalizeAllowedOrigins(array $origins) {
        return array_map('strtolower', $origins);
    }
    
    private function normalizeMsgSwapSize($bytes) {
        return filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'default' => 2097152,
            'min_range' => 0
        ]]);
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
        if (($queryString = $asgiEnv['QUERY_STRING']) || $queryString === '0') {
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
        
        $importCallback = function($socket) use ($asgiEnv) { $this->importSocket($socket, $asgiEnv); };
        
        return [
            Status::SWITCHING_PROTOCOLS,
            Reason::HTTP_101,
            $headers,
            $body = NULL,
            $importCallback
        ];
    }
    
    private function importSocket($socket, array $asgiEnv) {
        $requestUri = $asgiEnv['REQUEST_URI'];
        
        if (($queryString = $asgiEnv['QUERY_STRING']) || $queryString === '0') {
            $requestUri = str_replace("?{$queryString}", '', $requestUri);
        }
        
        $session = new ClientSession;
        
        $session->id = spl_object_hash($session);
        $this->endpointClientMap[$requestUri][$session->id] = $session;
        
        list($session->endpoint, $session->endpointOptions) = $this->endpoints[$requestUri];
        
        $session->endpointUri = $requestUri;
        $session->socket = $socket;
        $session->asgiEnv = $asgiEnv;
        $session->connectedAt = time();
        $session->clientProxy = new Client($this, $session);
        $session->frameWriter = new FrameWriter($socket);
        $session->frameParser = (new FrameParser)->setOptions([
            'msgSwapSize' => $session->endpointOptions['msgSwapSize'],
            'maxFrameSize' => $session->endpointOptions['maxFrameSize'],
            'maxMsgSize' => $session->endpointOptions['maxMsgSize']
        ]);
        
        $socketReadTimeout = $session->endpointOptions['heartbeatPeriod'] ?: $this->socketReadTimeout;
        
        $session->socketReadSubscription = $this->reactor->onReadable($socket,
            function($socket, $trigger) use ($session) { $this->read($session, $trigger); }
        , $socketReadTimeout);
        
        $this->sessions->attach($session);
        $this->onOpen($session);
    }
    
    private function onOpen(ClientSession $session) {
        try {
            $session->endpoint->onOpen($session->clientProxy);
        } catch (\Exception $e) {
            $this->onEndpointError($session, $e);
        }
    }
    
    private function read(ClientSession $session, $trigger) {
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
    private function doHeartbeat(ClientSession $session) {
        $data = pack('S', rand(0, 32768));
        $pingFrame = new Frame(Frame::FIN, Frame::RSV_NONE, Frame::OP_PING, $data);
        $session->frameWriter->enqueue($pingFrame);
        $this->write($session);
    }
    
    private function doSocketRead(ClientSession $session) {
        $data = @fread($session->socket, $this->socketReadGranularity);
        
        if ($data || $data === '0') {
            $session->dataLastReadAt = time();
            $this->parse($session, $data);
        } elseif (!is_resource($session->socket) || @feof($session->socket)) {
            $this->onClose($session, Codes::ABNORMAL_CLOSE, 'Client went away');
        }
    }
    
    private function parse(ClientSession $session, $parsableData) {
        try {
            while ($parsedMsgArr = $session->frameParser->parse($parsableData)) {
                $this->onParsedMessage($session, $parsedMsgArr);
                $parsableData = '';
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
    
    private function onParsedMessage(ClientSession $session, array $parsedMsgArr) {
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
    
    private function onMessage(ClientSession $session, Message $msg) {
        try {
            $session->endpoint->onMessage($session->clientProxy, $msg);
        } catch (\Exception $e) {
            $this->onEndpointError($session, $e);
        }
    }
    
    private function afterControlFrameRead(ClientSession $session, $opcode, $payload) {
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
    
    private function afterPingFrameRead(ClientSession $session, $payload) {
        $pongFrame = new Frame(Frame::FIN, Frame::RSV_NONE, Frame::OP_PONG, $payload);
        $session->frameWriter->enqueue($pongFrame);
        $this->write($session);
    }
    
    private function afterPongFrameRead(ClientSession $session, $payload) {
        for ($i=count($session->pendingPingPayloads)-1; $i>=0; $i--) {
            if ($session->pendingPingPayloads[$i] == $payload) {
                $session->pendingPingPayloads = array_slice($session->pendingPingPayloads, $i+1);
                break;
            }
        }
    }
    
    private function afterCloseFrameRead(ClientSession $session, $payload) {
        $session->closeState |= ClientSession::CLOSE_FRAME_RECEIVED;
        
        if ($session->closeState & ClientSession::CLOSE_HANDSHAKE_COMPLETE) {
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
    
    function broadcast($recipients, $opcode, $data, callable $afterSend = NULL) {
        try {
            $frameStream = $this->frameStreamFactory->__invoke($opcode, $data);
            $frameStream->setFrameSize($this->autoFrameSize);
            $recipients = $recipients instanceof ClientSession ? [$recipients] : $recipients;
            
            foreach ($recipients as $session) {
                $session->outboundFrameStreamQueue[] = [$frameStream, $afterSend];
                $this->enqueueNextDataFrame($session);
                $this->write($session);
            }
            
        } catch (\InvalidArgumentException $e) {
            $this->onEndpointError($session, $e);
        }
    }
    
    function close($recipients, $code, $reason) {
        $payload = pack('n', $code) . substr($reason, 0, 125);
        $frame = new Frame(Frame::FIN, Frame::RSV_NONE, Frame::OP_CLOSE, $payload);
        
        $recipients = $recipients instanceof ClientSession ? [$recipients] : $recipients;
        
        foreach ($recipients as $session) {
            $session->pendingCloseCode = $code;
            $session->pendingCloseReason = $reason;
            $session->frameWriter->enqueue($frame);
            $this->write($session);
        }
    }
    
    private function write(ClientSession $session) {
        try {
            if ($session->closeState & ClientSession::CLOSE_FRAME_SENT) {
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
    
    private function afterFrameWrite(ClientSession $session, Frame $frame) {
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
    
    private function afterCloseFrameWrite(ClientSession $session, Frame $frame) {
        $session->controlFramesWritten++;
        $session->controlBytesWritten += $frame->getLength();
        $session->closeState |= ClientSession::CLOSE_FRAME_SENT;
        
        if ($session->closeState & ClientSession::CLOSE_HANDSHAKE_COMPLETE) {
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
    
    private function afterPingFrameWrite(ClientSession $session, Frame $frame) {
        $session->controlFramesWritten++;
        $session->controlBytesWritten += $frame->getLength();
        
        // Punish naughty clients who don't respond to PINGs; we can't store these payloads forever.
        if (array_push($session->pendingPingPayloads, $frame->getPayload()) > $this->queuedPingLimit) {
            $code = Codes::POLICY_VIOLATION;
            $reason = 'Exceeded unanswered PING limit';
            $this->close($session, $code, $reason);
        }
        
        return ($isWritingComplete = !$session->frameWriter->canWrite());
    }
    
    private function afterPongFrameWrite(ClientSession $session, Frame $frame) {
        $session->controlFramesWritten++;
        $session->controlBytesWritten += $frame->getLength();
        
        return ($isWritingComplete = !$session->frameWriter->canWrite());
    }
    
    private function afterDataFrameWrite(ClientSession $session, Frame $frame) {
        $session->dataFramesWritten++;
        $session->dataBytesWritten += $frame->getLength();
        
        $wasLastFrameInMsg = $frame->isFin();
        
        if ($wasLastFrameInMsg) {
            $session->dataMessagesWritten++;
            $this->afterMessageSend($session);
        }
                
        if ($session->outboundFrameStream || $session->outboundFrameStreamQueue) {
            $this->enqueueNextDataFrame($session);
            $isWritingComplete = FALSE;
        } else {
            $isWritingComplete = TRUE;
        }
        
        return $isWritingComplete;
    }
    
    private function afterMessageSend(ClientSession $session) {
        try {
            if ($callback = $session->afterMessageSend) {
                $session->afterMessageSend = NULL;
                $callback($session->clientProxy);
            }
        } catch (\Exception $userlandException) {
            throw new EndpointException(
                'afterMessage callback threw :(',
                NULL,
                $userlandException
            );
        }
    }
    
    private function enqueueNextDataFrame(ClientSession $session) {
        if (!$session->outboundFrameStream) {
            list($nextStream, $afterSend) = array_shift($session->outboundFrameStreamQueue);
            $session->outboundFrameStream = $nextStream;
            $session->afterMessageSend = $afterSend;
        } else {
            $session->outboundFrameStream->seek($session->outboundFrameStreamPosition);
        }
        
        try {
            $opcode = $session->outboundFrameStream->isBinary() ? Frame::OP_BIN : Frame::OP_TEXT;
            $payload = $session->outboundFrameStream->current();
            
            $session->outboundFrameStream->next();
            $session->outboundFrameStreamPosition = $session->outboundFrameStream->key();
            
            if ($isFin = !$session->outboundFrameStream->valid()) {
                $session->outboundFrameStream = $session->outboundFrameStreamPosition = NULL;
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
    
    private function onWriteFailure(ClientSession $session, FrameWriteException $e) {
        $frame = $e->getFrame();
        $bytesCompleted = $e->getBytesCompleted();
        $isControlFrame = ($frame->getOpcode() >= Frame::OP_CLOSE);
        
        if ($isControlFrame) {
            $session->controlBytesWritten += $bytesCompleted;
        } else {
            $session->dataBytesWritten += $bytesCompleted;
        }
        
        $code = Codes::ABNORMAL_CLOSE;
        $reason = 'Connection severed unexpectedly';
        $this->onClose($session, $code, $reason);
    }
    
    private function onEndpointError(ClientSession $session, $e) {
        @fwrite($session->asgiEnv['ASGI_ERROR'], $e);
        
        $code = Codes::UNEXPECTED_SERVER_ERROR;
        $reason = 'Unexpected internal error :(';
        $this->close($session, $code, $reason);
    }
    
    private function onClose(ClientSession $session, $code, $reason) {
        try {
            $this->unloadSession($session);
            $session->endpoint->onClose($session->clientProxy, $code, $reason);
        } catch (\Exception $e) {
            // The client has already disconnected so the only thing to do here is log the error
            @fwrite($session->asgiEnv['ASGI_ERROR'], $e);
        }
    }
    
    /**
     * @TODO Allow the use of SO_LINGER = 0 on closes
     */
    private function unloadSession(ClientSession $session) {
        if (is_resource($session->socket)) {
            @fclose($session->socket);
        }
        
        if ($session->socketReadSubscription) {
            $session->socketReadSubscription->cancel();
        }
        
        if ($session->socketWriteSubscription) {
            $session->socketWriteSubscription->cancel();
        }
        
        if ($session->closeTimeoutSubscription) {
            $session->closeTimeoutSubscription->cancel();
        }
        
        unset($this->endpointClientMap[$session->endpointUri][$session->id]);
        
        $this->sessions->detach($session);
    }
    
    private function enableWriteSubscription(ClientSession $session) {
        if ($session->socketWriteSubscription) {
            $session->socketWriteSubscription->enable();
        } else {
            $subscription = $this->reactor->onWritable($session->socket, function() use ($session) {
                $this->write($session);
            });
            $session->socketWriteSubscription = $subscription;
        }
    }
    
    private function disableWriteSubscription(ClientSession $session) {
        if ($session->socketWriteSubscription) {
            $session->socketWriteSubscription->disable();
        }
    }
    
    function count($endpointUri = NULL) {
        if (isset($endpointUri, $this->endpointClientMap[$endpointUri])) {
            $clientCount = count($this->endpointClientMap[$endpointUri]);
        } else {
            $clientCount = $this->sessions->count();
        }
        
        return $clientCount;
    }
}
