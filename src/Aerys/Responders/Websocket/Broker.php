<?php

namespace Aerys\Responders\Websocket;

use Alert\Reactor,
    Aerys\Status,
    Aerys\Reason,
    Aerys\Response,
    Aerys\Server,
    Aerys\Request;

class Broker implements \Countable {

    const ACCEPT_CONCAT = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private static $STOPPED = 0;
    private static $STARTED = 1;
    private static $STOPPING = 2;

    private $state;
    private $reactor;
    private $server;
    private $frameStreamFactory;
    private $sessions = [];
    private $endpoints = [];
    private $heartbeats = [];
    private $heartbeatWatcher;
    private $heartbeatWatchInterval = 1;
    private $serverStopBlockerId;
    private $queuedPingLimit = 3;
    private $closeResponseTimeout = 5;
    private $autoFrameSize = 65365;
    private $socketReadGranularity = 65365;
    private $defaultEndpointOptions = [
        'subprotocol'      => NULL,
        'allowedOrigins'   => [],
        'maxFrameSize'     => 2097152,
        'maxMsgSize'       => 10485760,
        'heartbeatPeriod'  => 10,
        'validateUtf8Text' => TRUE
        // @TODO add minimum average frame size rate threshold to prevent tiny-frame DoS
    ];

    function __construct(Reactor $reactor, Server $server, FrameStreamFactory $frameStreamFactory = NULL) {
        $this->state = self::$STOPPED;
        $this->reactor = $reactor;
        $this->server = $server;
        $this->frameStreamFactory = $frameStreamFactory ?: new FrameStreamFactory;
        $this->heartbeatWatcher = $reactor->repeat(function() {
            $this->sendHeartbeats();
        }, $this->heartbeatWatchInterval);
        $this->server->addObserver(Server::STARTED, function() { $this->onServerStart(); });
        $this->server->addObserver(Server::STOPPING, function() { $this->onServerStopping(); });
    }

    /**
     * Send UTF-8 text to one or more connected sockets
     *
     * @param mixed $socketIdOrArray A socket ID or array of socket IDs
     * @param mixed $data Any string or seekable stream resource
     * @param callable $afterSend An optional callback to invoke after the non-blocking send completes
     */
    function sendText($socketIdOrArray, $data, callable $afterSend = NULL) {
        $this->broadcast($socketIdOrArray, Frame::OP_TEXT, $data, $afterSend);
    }

    /**
     * Send binary data to one or more connected sockets
     *
     * @param mixed $socketIdOrArray A socket ID or array of socket IDs
     * @param mixed $data Any string or seekable stream resource
     * @param callable $afterSend An optional callback to invoke after the non-blocking send completes
     */
    function sendBinary($socketIdOrArray, $data, callable $afterSend = NULL) {
        $this->broadcast($socketIdOrArray, Frame::OP_BIN, $data, $afterSend);
    }

    /**
     * Retrieve the ASGI request environment used to originate the user's websocket connection
     *
     * @param int $socketId A client-identifying socket ID
     * @throws \DomainException On unknown socket ID
     * @return array The ASGI request environment used to initiate the specified ID's websocket session
     */
    function getEnvironment($socketId) {
        if (isset($this->sessions[$socketId])) {
            $session = $this->sessions[$socketId];
            return $session->request;
        } else {
            throw new \DomainException(
                "Unknown socket ID: {$socketId}"
            );
        }
    }

    /**
     * Retrieve aggregate IO statistics for the specified socket ID
     *
     * @param int $socketId A client-identifying socket ID
     * @throws \DomainException On unknown socket ID
     * @return array A map of numeric stats for the specified socket ID
     */
    function getStats($socketId) {
        if (!isset($this->sessions[$socketId])) {
            throw new \DomainException(
                "Unknown socket ID: {$socketId}"
            );
        }

        $session = $this->sessions[$socketId];

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
     * Note that this returns the aggregate total for ALL endpoints.
     *
     * @return int
     */
    function count() {
        return count($this->sessions);
    }

    /**
     * Manually initiate a websocket close handshake
     *
     * @param mixed $socketIdOrArray A socket ID or an array of socket IDs to close
     * @return void
     */
    function close($socketIdOrArray, $code, $reason) {
        try {
            $recipients = $this->generateRecipientList($socketIdOrArray);

            $payload = pack('n', $code) . substr($reason, 0, 125);
            $frame = new Frame(Frame::FIN, Frame::RSV_NONE, Frame::OP_CLOSE, $payload);

            foreach ($recipients as $socketId) {
                $session = $this->sessions[$socketId];
                $session->pendingCloseCode = $code;
                $session->pendingCloseReason = $reason;
                $session->frameWriter->enqueue($frame);
                $this->doSessionWrite($session);
            }
        } catch (UnknownSocketException $e) {
            $this->onEndpointError($e);

            if ($recipients = array_diff($recipients, $e->getUnknownSocketIds())) {
                $this->close($recipients, $code, $reason);
            }
        }
    }

    private function generateRecipientList($socketIdOrArray) {
        $recipients = is_array($socketIdOrArray) ? $socketIdOrArray : [$socketIdOrArray];

        if ($unknownSocketIds = array_diff_key(array_flip($recipients), $this->sessions)) {
            throw new UnknownSocketException(
                $unknownSocketIds,
                'Unknown socket ID(s): ' . implode(', ', $unknownSocketIds)
            );
        }

        return $recipients;
    }

    /**
     * Register an Endpoint implementation to handle websocket events on the specified URI path
     *
     * @param string $requestUriPath
     * @param \Aerys\Responders\Websocket\Endpoint $endpoint
     * @param array $options A map of endpoint-specific policy options
     * @throw \InvalidArgumentException On bad URI path
     * @return void
     */
    function registerEndpoint($requestUriPath, Endpoint $endpoint, array $options = []) {
        if ($requestUriPath[0] !== '/') {
            throw new \InvalidArgumentException(
                'Endpoint URI path must begin with a forward slash'
            );
        }

        $options = empty($options)
            ? $this->defaultEndpointOptions
            : $this->normalizeEndpointOptions($options);

        $this->endpoints[$requestUriPath] = [$endpoint, $options];
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
     * Respond to the request (i.e. attempt the websocket handshake)
     *
     * @param array $request The request environment map
     * @return \Aerys\Response
     */
    function __invoke($request) {
        list($isAccepted, $handshakeResult) = $this->validateClientHandshake($request);

        if ($isAccepted) {
            list($version, $protocol, $extensions) = $handshakeResult;
            $responseArray = $this->generateServerHandshake($request, $version, $protocol, $extensions);
        } else {
            $responseArray = $handshakeResult;
        }
        
        return new Response($responseArray);
    }

    private function validateClientHandshake($request) {
        $requestUriPath = $request['REQUEST_URI_PATH'];

        if (isset($this->endpoints[$requestUriPath])) {
            $endpointOptions = $this->endpoints[$requestUriPath][1];
        } else {
            return [FALSE, ['status' => Status::NOT_FOUND, 'reason' => Reason::HTTP_404]];
        }

        if ($request['REQUEST_METHOD'] != 'GET') {
            return [FALSE, ['status' => Status::METHOD_NOT_ALLOWED, 'reason' => Reason::HTTP_405]];
        }

        if ($request['SERVER_PROTOCOL'] < 1.1) {
            return [FALSE, ['status' => Status::HTTP_VERSION_NOT_SUPPORTED, 'reason' => Reason::HTTP_505]];
        }

        if (empty($request['HTTP_UPGRADE']) || strcasecmp($request['HTTP_UPGRADE'], 'websocket')) {
            return [FALSE, ['status' => Status::UPGRADE_REQUIRED, 'reason' => Reason::HTTP_426]];
        }

        if (empty($request['HTTP_CONNECTION']) || !$this->validateConnectionHeader($request['HTTP_CONNECTION'])) {
            $reason = 'Bad Request: "Connection: Upgrade" header required';
            return [FALSE, ['status' => Status::BAD_REQUEST, 'reason' => $reason]];
        }

        if (empty($request['HTTP_SEC_WEBSOCKET_KEY'])) {
            $reason = 'Bad Request: "Sec-Broker-Key" header required';
            return [FALSE, ['status' => Status::BAD_REQUEST, 'reason' => $reason]];
        }

        if (empty($request['HTTP_SEC_WEBSOCKET_VERSION'])) {
            $reason = 'Bad Request: "Sec-WebSocket-Version" header required';
            return [FALSE, ['status' => Status::BAD_REQUEST, 'reason' => $reason]];
        }

        $version = NULL;
        $requestedVersions = explode(',', $request['HTTP_SEC_WEBSOCKET_VERSION']);
        foreach ($requestedVersions as $requestedVersion) {
            if ($requestedVersion === '13') {
                $version = 13;
                break;
            }
        }

        if (!$version) {
            $reason = 'Bad Request: Requested WebSocket version(s) unavailable';
            $headers = ['Sec-WebSocket-Version: 13'];
            return [FALSE, ['status' => Status::BAD_REQUEST, 'reason' => $reason, 'headers' => $headers]];
        }

        $allowedOrigins = $endpointOptions['allowedOrigins'];
        $originHeader = empty($request['HTTP_ORIGIN']) ? NULL : $request['HTTP_ORIGIN'];
        if ($allowedOrigins && !in_array($originHeader, $allowedOrigins)) {
            return [FALSE, ['status' => Status::FORBIDDEN, 'reason' => Reason::HTTP_403]];
        }

        $subprotocol = $endpointOptions['subprotocol'];
        $subprotocolHeader = !empty($request['HTTP_SEC_WEBSOCKET_PROTOCOL'])
            ? explode(',', $request['HTTP_SEC_WEBSOCKET_PROTOCOL'])
            : [];

        if ($subprotocol && !in_array($subprotocol, $subprotocolHeader)) {
            $reason = 'Bad Request: Requested WebSocket subprotocol(s) unavailable';
            return [FALSE, ['status' => Status::BAD_REQUEST, 'reason' => $reason]];
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

    private function generateServerHandshake($request, $version, $subprotocol, $extensions) {
        $concatenatedKeyStr = $request['HTTP_SEC_WEBSOCKET_KEY'] . self::ACCEPT_CONCAT;
        $secWebSocketAccept = base64_encode(sha1($concatenatedKeyStr, TRUE));

        $headers = [
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Accept: {$secWebSocketAccept}"
        ];

        if ($subprotocol || $subprotocol === '0') {
            $headers[] = "Sec-WebSocket-Protocol: {$subprotocol}";
        }

        if ($extensions) {
            $headers[] = 'Sec-WebSocket-Extensions: ' . implode(',', $extensions);
        }

        return [
            'status' => Status::SWITCHING_PROTOCOLS,
            'reason' => Reason::HTTP_101,
            'headers' => $headers,
            'export_callback' => [$this, 'importSocket']
        ];
    }

    function importSocket($socket, $request, callable $closeCallback) {
        $socketId = (int) $socket;

        $requestUriPath = $request['REQUEST_URI_PATH'];

        list($endpoint, $endpointOptions) = $this->endpoints[$requestUriPath];

        $session = new Session;

        $session->closeCallback = $closeCallback;
        $session->id = $socketId;
        $session->socket = $socket;
        $session->request = $request;
        $session->connectedAt = time();
        $session->endpoint = $endpoint;
        $session->endpointOptions = $endpointOptions;
        $session->endpointUri = $requestUriPath;
        $session->frameWriter = new FrameWriter($socket);
        $session->frameParser = (new FrameParser)->setOptions([
            'maxFrameSize' => $session->endpointOptions['maxFrameSize'],
            'maxMsgSize' => $session->endpointOptions['maxMsgSize'],
            'validateUtf8Text' => $session->endpointOptions['validateUtf8Text']
        ]);

        $onReadable = function() use ($session) { $this->doSocketRead($session); };
        $session->readWatcher = $this->reactor->onReadable($socket, $onReadable);

        $onWritable = function() use ($session) { $this->doSessionWrite($session); };
        $session->writeWatcher = $this->reactor->onWritable($socket, $onWritable, $enableNow = FALSE);

        $this->sessions[$socketId] = $session;

        $heartbeatPeriod = $endpointOptions['heartbeatPeriod'];
        $session->heartbeatPeriod = $heartbeatPeriod > 0 ? $heartbeatPeriod : 0;
        $this->renewHeartbeat($session);

        $this->onOpen($session);
    }

    private function onOpen(Session $session) {
        try {
            $result = $session->endpoint->onOpen($this, $session->id);
            if ($result instanceof \Generator) {
                $this->processGeneratorYield($result);
            } elseif (is_string($result) || is_resource($result)) {
                $this->broadcast($session->id, Frame::OP_TEXT, $result);
            }
        } catch (\Exception $userlandException) {
            $endpointError = new EndpointException(
                get_class($session->endpoint) . '::onOpen() threw an uncaught exception',
                $errorCode = 0,
                $userlandException
            );
            $this->onEndpointError($endpointError);
        }
    }

    private function doSocketRead(Session $session) {
        $data = @fread($session->socket, $this->socketReadGranularity);
        $this->renewHeartbeat($session);

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
        } catch (\RuntimeException $e) {
            $code = Codes::UNEXPECTED_SERVER_ERROR;
            $reason = $e->getMessage();
            $this->close($session, $code, $reason);
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
            $result = $session->endpoint->onMessage($this, $session->id, $msg);
            if ($result instanceof \Generator) {
                $this->processGeneratorYield($result, $session->id);
            } elseif (is_string($result) || is_resource($result)) {
                $this->broadcast($session->id, Frame::OP_TEXT, $result);
            }
        } catch (\Exception $userlandException) {
            $endpointError = new EndpointException(
                get_class($session->endpoint) . '::onMessage() threw an uncaught exception',
                $errorCode = 0,
                $userlandException
            );
            $this->onEndpointError($endpointError);
        }
    }

    private function processGeneratorYield(\Generator $generator, $socketId = NULL) {
        $key = $generator->key();
        $value = $generator->current();

        if (is_callable($key)) {
            $value = is_array($value) ? $value : [$value];
            array_push($value, function() use ($generator, $socketId) {
                $generator->send(func_get_args());
                $this->processGeneratorYield($generator, $socketId);
            });
            call_user_func_array($key, $value);
        } elseif (is_callable($value)) {
            $value(function() use ($generator, $socketId) {
                $generator->send(func_get_args());
                $this->processGeneratorYield($generator, $socketId);
            });
        } elseif (isset($socketId) && (is_string($value) || is_resource($value))) {
            $this->broadcast($socketId, Frame::OP_TEXT, $value);
        } elseif (isset($value)) {
            $generator->throw(new EndpointException(
                sprintf('Invalid generator yield result: %s', gettype($value))
            ));
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
            $recipients = $this->generateRecipientList($recipients);

            $frameStream = $this->frameStreamFactory->__invoke($opcode, $data);
            $frameStream->setFrameSize($this->autoFrameSize);

            if ($unknownSocketIds = array_diff_key(array_flip($recipients), $this->sessions)) {
                throw new \DomainException(
                    'Unknown socket ID(s): ' . implode(', ', $unknownSocketIds)
                );
            }

            foreach ($recipients as $socketId) {
                $session = $this->sessions[$socketId];
                $session->frameStreamQueue[] = [$frameStream, $afterSend];
                $this->enqueueNextDataFrame($session);
                $this->doSessionWrite($session);
            }

        } catch (\InvalidArgumentException $e) {
            $endpointError = new EndpointException(
                'Invalid broadcast data type; string or seekable stream resource required',
                $errorCode = 0,
                $previousException = $e
            );
            $this->onEndpointError($endpointError);
        } catch (UnknownSocketException $e) {
            $this->onEndpointError($e);
            if ($recipients = array_diff($recipients, $e->getUnknownSocketIds())) {
                $this->broadcast($recipients, $opcode, $data, $afterSend);
            }
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
                $this->reactor->disable($session->writeWatcher);
            } else {
                $this->reactor->enable($session->writeWatcher);
            }

            $this->renewHeartbeat($session);

        } catch (FrameWriteException $e) {
            $this->onWriteFailure($session, $e);
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
            $session->closeTimeoutWatcher = $this->reactor->once(function() use ($session) {
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
            $endpointError = new EndpointException(
                get_class($session->endpoint) . ' afterWrite callback threw an uncaught exception',
                $errorCode = 0,
                $userlandException
            );
            $this->onEndpointError($endpointError);
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
            @fwrite($session->request['ASGI_ERROR'], $e);

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

    private function onEndpointError(EndpointException $e) {
        $this->server->logError($e);
    }

    private function onClose(Session $session, $code, $reason) {
        try {
            $this->unloadSession($session);
            $result = $session->endpoint->onClose($this, $session->id, $code, $reason);
            if ($result instanceof \Generator) {
                $this->processGeneratorYield($result);
            }
        } catch (\Exception $userlandException) {
            $endpointError = new EndpointException(
                get_class($session->endpoint) . '::onClose() threw an uncaught exception',
                $errorCode = 0,
                $userlandException
            );
            $this->onEndpointError($endpointError);
        }
    }

    private function unloadSession(Session $session) {
        $closeCallback = $session->closeCallback;
        $closeCallback();
        $this->reactor->cancel($session->readWatcher);
        $this->reactor->cancel($session->writeWatcher);

        if ($session->closeTimeoutWatcher) {
            $this->reactor->cancel($session->closeTimeoutWatcher);
        }

        $socketId = $session->id;

        unset(
            $this->sessions[$socketId],
            $this->heartbeats[$socketId]
        );

        if ($this->state === self::$STOPPING && empty($this->sessions)) {
            $this->state = self::$STOPPED;
            $this->server->allowStop($this->serverStopBlockerId);
            $this->serverStopBlockerId = NULL;
        }
    }

    private function sendHeartbeats() {
        $now = time();
        foreach ($this->heartbeats as $socketId => $expiryTime) {
            if ($expiryTime <= $now) {
                $session = $this->sessions[$socketId];
                $this->sendHeartbeatPing($session);
            } else {
                break;
            }
        }
    }

    /**
     * At the time of this writing some browsers (I'm looking at you, Chrome) will not respond
     * to PING frames that don't carry application data in the frame payload. To correct for this,
     * we ensure there is always a payload attached to each outbound PING frame.
     *
     * @link http://tools.ietf.org/html/rfc6455#section-5.5.2
     */
    private function sendHeartbeatPing(Session $session) {
        $data = pack('S', rand(0, 32768));
        $pingFrame = new Frame(Frame::FIN, Frame::RSV_NONE, Frame::OP_PING, $data);
        $session->frameWriter->enqueue($pingFrame);
        $this->doSessionWrite($session);
    }

    private function renewHeartbeat(Session $session) {
        if ($session->heartbeatPeriod) {
            $socketId = $session->id;
            unset($this->heartbeats[$socketId]);
            $this->heartbeats[$socketId] = time() + $session->heartbeatPeriod;
        }
    }

    private function onServerStart() {
        $this->state = self::$STARTED;
    }

    private function onServerStopping() {
        if ($this->sessions) {
            $this->state = self::$STOPPING;
            $this->serverStopBlockerId = $this->server->preventStop();
            $allSocketIds = array_keys($this->sessions);
            $this->close($allSocketIds, Codes::GOING_AWAY, 'Server shutting down');
        } else {
            $this->state = self::$STOPPED;
            $this->server->allowStop($this->serverStopBlockerId);
            $this->serverStopBlockerId = NULL;
        }
    }

}
