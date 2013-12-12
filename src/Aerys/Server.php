<?php

namespace Aerys;

use Alert\Reactor,
    Aerys\Parsing\Parser,
    Aerys\Parsing\ParserFactory,
    Aerys\Parsing\ParseException,
    Aerys\Writing\WriterFactory,
    Aerys\Writing\ResourceException;

class Server {

    const NAME = 'Aerys/0.1.0-devel';
    const VERSION = '0.1.0';

    const STOPPED = 0;
    const STARTED = 1;
    const PAUSED = 2;
    const STOPPING = 3;
    const NEED_STOP_PERMISSION = 4;

    private $state;
    private $reactor;
    private $hostBinder;
    private $parserFactory;
    private $writerFactory;
    private $responseNormalizer;
    private $hosts;
    private $listeningSockets = [];
    private $acceptWatchers = [];
    private $pendingTlsWatchers = [];
    private $clients = [];
    private $exportedSocketIdMap = [];
    private $cachedClientCount = 0;
    private $lastRequestId = 0;
    private $inProgressMessageCycle;

    private $now;
    private $httpDateNow;
    private $httpDateFormat = 'D, d M Y H:i:s';
    private $keepAliveWatcher;
    private $keepAliveTimeouts = [];
    private $forceStopWatcher;
    private $stopBlockers = [];
    private $observations = [];

    private $errorLogPath;
    private $errorStream = STDERR;
    private $maxConnections = 1500;
    private $maxRequests = 150;
    private $keepAliveTimeout = 5;
    private $defaultHost;
    private $defaultContentType = 'text/html';
    private $defaultTextCharset = 'utf-8';
    private $autoReasonPhrase = TRUE;
    private $sendServerToken = FALSE;
    private $disableKeepAlive = FALSE;
    private $socketSoLingerZero = FALSE;
    private $normalizeMethodCase = TRUE;
    private $requireBodyLength = TRUE;
    private $maxHeaderBytes = 8192;
    private $maxBodyBytes = 2097152;
    private $allowedMethods = ['GET', 'HEAD', 'OPTIONS', 'TRACE', 'PUT', 'POST', 'PATCH', 'DELETE'];
    private $cryptoType = STREAM_CRYPTO_METHOD_TLS_SERVER; // @TODO Add option setter
    private $readGranularity = 262144; // @TODO Add option setter
    private $showErrors = TRUE;
    private $stopTimeout = -1;

    private $isExtSocketsEnabled;

    public function __construct(
        Reactor $reactor,
        HostBinder $hb = NULL,
        ParserFactory $pf = NULL,
        WriterFactory $wf = NULL,
        ResponseNormalizer $rn = NULL
    ) {
        $this->state = self::STOPPED;
        $this->reactor = $reactor;
        $this->hostBinder = $hb ?: new HostBinder;
        $this->parserFactory = $pf ?: new ParserFactory;
        $this->writerFactory = $wf ?: new WriterFactory;
        $this->responseNormalizer = $rn ?: new ResponseNormalizer;
        $this->isExtSocketsEnabled = extension_loaded('sockets');
    }

    /**
     * Listen for HTTP traffic
     *
     * IMPORTANT: The server's event reactor must still be started externally or nothing will happen.
     *
     * @param mixed $hostOrCollection A Host, HostCollection or array of Host instances
     * @param array $listeningSockets Optional array mapping bind addresses to existing sockets
     * @throws \LogicException If server is running
     * @throws \LogicException If no hosts have been added to the specified collection
     * @throws \RuntimeException On socket bind failure
     * @return void
     */
    public function start($hostOrCollection, array $listeningSockets = []) {
        if ($this->state !== self::STOPPED) {
            throw new \LogicException(
                'Cannot start; server already running!'
            );
        }

        $this->hosts = $this->normalizeStartHosts($hostOrCollection);
        $this->listeningSockets = $this->hostBinder->bindHosts($this->hosts, $listeningSockets);
        $tlsMap = $this->hosts->getTlsBindingsByAddress();
        $acceptor = function($watcherId, $serverSocket) { $this->accept($serverSocket); };
        $tlsAcceptor = function($watcherId, $serverSocket) { $this->acceptTls($serverSocket); };

        foreach ($this->listeningSockets as $bindAddress => $serverSocket) {
            if (isset($tlsMap[$bindAddress])) {
                stream_context_set_option($serverSocket, $tlsMap[$bindAddress]);
                $acceptCallback = $tlsAcceptor;
            } else {
                $acceptCallback = $acceptor;
            }

            $acceptWatcher = $this->reactor->onReadable($serverSocket, $acceptCallback);
            $this->acceptWatchers[$bindAddress] = $acceptWatcher;
        }

        $this->initializeKeepAliveWatcher();
        $this->notifyObservers(self::STARTED);
    }

    private function normalizeStartHosts($hostOrCollection) {
        if ($hostOrCollection instanceof HostCollection) {
            $hosts = $hostOrCollection;
        } elseif ($hostOrCollection instanceof Host) {
            $hosts = new HostCollection;
            $hosts->addHost($hostOrCollection);
        } elseif (is_array($hostOrCollection)) {
            $hosts = new HostCollection;
            foreach ($hostOrCollection as $host) {
                $hosts->addHost($host);
            }
        }

        return $hosts;
    }

    private function initializeKeepAliveWatcher() {
        $this->renewHttpDate();
        $this->keepAliveWatcher = $this->reactor->repeat(function() {
            $this->timeoutKeepAlives();
        }, $intervalInSeconds = 1);
    }

    public function addObserver($event, callable $observer, array $options = []) {
        $observation = new ServerObservation($event, $observer, $options);
        $this->observations[$event][] = $observation;
        usort($this->observations[$event], [$this, 'observerPrioritySort']);

        return $observation;
    }

    private function observerPrioritySort(ServerObservation $a, ServerObservation $b) {
        $a = $a->getPriority();
        $b = $b->getPriority();
        return ($a != $b) ? ($a - $b) : 0;
    }

    public function removeObserver(ServerObservation $observation) {
        foreach ($this->observations as $event => $list) {
            foreach ($list as $key => $match) {
                if ($match === $observation) {
                    unset($this->observations[$event][$key]);
                }
            }
        }
    }

    /**
     * Register an object to temporarily prevent full server shutdown
     *
     * Calling this method prevents Server::stop() calls from returning until the associated object
     * is passed to Server::allowStop().
     *
     * @return string Unique stop ID; string must be passed back to Server:allowStop()
     */
    public function preventStop() {
        $stopId = uniqid($prefix = '', $moreEntropy = TRUE);
        $this->stopBlockers[$stopId] = TRUE;

        return $stopId;
    }

    /**
     * Remove the server shutdown block associated with this object
     *
     * @param string $stopId A stop ID reference string previously obtained from Server::preventStop()
     * @return bool Returns TRUE if the stop ID was cleared or FALSE if the ID was not recognized
     */
    public function allowStop($stopId) {
        if (isset($this->stopBlockers[$stopId])) {
            $wasCleared = TRUE;
        } else {
            $wasCleared = FALSE;
        }

        return $wasCleared;
    }

    /**
     * Stop the server gracefully (or not)
     *
     * By default the server will take as long as necessary to complete the currently outstanding
     * requests ($timeout = -1) when stop() is called. New client acceptance will be suspended,
     * previously assigned responses will be sent in full and any currently unfulfilled requests
     * will be sent a 503 Service Unavailable response.
     *
     * If the optional $timeout parameter is greater than zero client connections will be forcibly
     * closed and the server will stop if the shutdown hasn't completed when the timeout expires. A
     * timeout value of 0 will force an immediate (ungraceful) stop.
     *
     * Repeated calls to this method will have no effect if the server is already in the process of
     * stopping. Calls to Server::stop() will block until completion.
     *
     * @param int $timeout Timeout value in seconds
     * @return void
     */
    public function stop($timeout = NULL) {
        if ($this->state === self::STOPPING
            || $this->state === self::NEED_STOP_PERMISSION
            || $this->state === self::STOPPED
        ) {
            return;
        }

        if (!is_null($timeout)) {
            $this->setStopTimeout($timeout);
        }

        $this->notifyObservers(self::STOPPING);

        foreach ($this->acceptWatchers as $watcherId) {
            $this->reactor->cancel($watcherId);
        }
        foreach ($this->pendingTlsWatchers as $client) {
            $this->failTlsConnection($client);
        }
        foreach ($this->clients as $client) {
            stream_socket_shutdown($client->socket, STREAM_SHUT_RD);
        }

        // This property is always NULL unless a fatal error instigated the server stop event. When
        // a fatal occurs all unfulfilled requests are sent a 503 response. We use this property to
        // instead send a 500 for the specific request that resulted in the fatal and allow mods
        // the opportunity to capture information about the request that caused the 500.
        if ($this->inProgressMessageCycle) {
            $this->assignFatal500Response();
        }

        if ($this->timeout === 0) {
            $this->forceStop();
        } elseif ($this->clients) {
            $this->stopGracefully();
        }

        $this->onStopCompletion();
    }

    private function notifyObservers($event) {
        if (!empty($this->observations[$event])) {
            foreach ($this->observations[$event] as $observation) {
                $callback = $observation->getCallback();
                $callback($this, $event);
            }
        }
    }

    private function forceStop() {
        foreach ($this->clients as $client) {
            $this->closeClient($client);
        }
    }

    private function stopGracefully() {
        $response = new Response([
            'status' => Status::SERVICE_UNAVAILABLE,
            'reason' => Reason::HTTP_503,
            'headers' => ['Connection: close'],
            'body' => '<html><body><h1>503 Service Unavailable</h1></body></html>'
        ]);

        if ($this->clients && $this->timeout !== -1) {
            $forceStopper = function() { $this->forceStop(); };
            $this->forceStopWatcher = $this->reactor->once($forceStopper, $this->timeout);
        }

        foreach ($this->clients as $client) {
            $this->stopClient($client, $response);
        }

        while ($this->state === self::STOPPING) {
            $this->reactor->tick();
        }
    }

    private function stopClient(Client $client, Response $response) {
        if ($client->messageCycles) {
            $unassignedRequestIds = array_keys(array_diff_key($client->messageCycles, $client->pipeline));
            foreach ($unassignedRequestIds as $requestId) {
                $messageCycle = $client->messageCycles[$requestId];
                $messageCycle->response = $response;
                $this->respond($messageCycle);
            }
        } else {
            $this->closeClient($client);
        }
    }

    private function onStopCompletion() {
        $this->reactor->cancel($this->keepAliveWatcher);

        if ($this->forceStopWatcher) {
            $this->reactor->cancel($this->forceStopWatcher);
            $this->forceStopWatcher = NULL;
        }

        $this->acceptWatchers = [];
        $this->listeningSockets = [];

        while ($this->stopBlockers) {
            $this->reactor->tick();
        }

        $this->notifyObservers(self::STOPPED);
    }

    private function assignFatal500Response() {
        if ($this->showErrors) {
            $lastError = error_get_last();
            extract($lastError);
            $errorMsg = sprintf("%s in %s on line %d", $message, $file, $line);
        } else {
            $errorMsg = 'Something went terribly wrong';
        }

        $body = sprintf('<html><body><h1>500 Internal Server Error</h1><hr/><p>%s</p></body></html>', $errorMsg);
        $headers = [
            'Content-Type: text/html; charset=utf-8',
            'Content-Length: ' . strlen($body),
            'Connection: close'
        ];

        $messageCycle = $this->inProgressMessageCycle;
        $this->inProgressMessageCycle = NULL;

        $this->respond($messageCycle, new Response([
            'status' => Status::INTERNAL_SERVER_ERROR,
            'reason' => Reason::HTTP_500,
            'headers' => $headers,
            'body' => $body
        ]));
    }

    private function accept($serverSocket) {
        while ($client = @stream_socket_accept($serverSocket, $timeout = 0)) {
            stream_set_blocking($client, FALSE);
            $this->cachedClientCount++;
            $this->onClient($client, $isEncrypted = FALSE);
            if ($this->maxConnections > 0 && $this->cachedClientCount >= $this->maxConnections) {
                $this->pauseClientAcceptance();
                break;
            }
        }
    }

    private function pauseClientAcceptance() {
        foreach ($this->acceptWatchers as $watcherId) {
            $this->reactor->disable($watcherId);
        }
        if ($this->state !== self::STOPPING) {
            $this->state = self::PAUSED;
        }
    }

    private function resumeClientAcceptance() {
        foreach ($this->acceptWatchers as $watcherId) {
            $this->reactor->enable($watcherId);
        }

        if ($this->state !== self::STOPPING) {
            $this->state = self::STARTED;
        }
    }

    private function acceptTls($serverSocket) {
        while ($client = @stream_socket_accept($serverSocket, $timeout = 0)) {
            stream_set_blocking($client, FALSE);
            $this->cachedClientCount++;
            $cryptoEnabler = function() use ($client) { $this->doTlsHandshake($client); };
            $clientId = (int) $client;
            $tlsWatcher = $this->reactor->onReadable($client, $cryptoEnabler);
            $this->pendingTlsWatchers[$clientId] = $tlsWatcher;
            if ($this->maxConnections > 0 && $this->cachedClientCount >= $this->maxConnections) {
                $this->pauseClientAcceptance();
                break;
            }
        }
    }

    private function doTlsHandshake($client) {
        $isTlsHandshakeSuccessful = @stream_socket_enable_crypto($client, TRUE, $this->cryptoType);

        if ($isTlsHandshakeSuccessful) {
            $this->clearPendingTlsClient($client);
            $this->onClient($client, $isEncrypted = TRUE);
        } elseif ($isTlsHandshakeSuccessful === FALSE) {
            $this->failTlsConnection($client);
        }
    }

    private function failTlsConnection($client) {
        $this->clearPendingTlsClient($client);

        if (is_resource($client)) {
            @fclose($client);
        }

        $this->cachedClientCount--;

        if ($this->state === self::PAUSED
            && $this->maxConnections > 0
            && $this->cachedClientCount <= $this->maxConnections
        ) {
            $this->resumeClientAcceptance();
        }
    }

    private function clearPendingTlsClient($client) {
        $clientId = (int) $client;

        if ($cryptoWatcher = $this->pendingTlsWatchers[$clientId]) {
            $this->reactor->cancel($cryptoWatcher);
        }

        unset($this->pendingTlsWatchers[$clientId]);
    }

    private function timeoutKeepAlives() {
        $now = $this->renewHttpDate();

        foreach ($this->keepAliveTimeouts as $socketId => $expiryTime) {
            if ($expiryTime <= $now) {
                $client = $this->clients[$socketId];
                $this->closeClient($client);
            } else {
                break;
            }
        }
    }

    /**
     * Date string generation is (relatively) expensive. Since we only need HTTP dates at a
     * granularity of one second we're better off to generate this information once per second and
     * cache it. Because we also timeout keep-alive connections at one-second intervals we cache
     * the unix timestamp for comparisons against client activity times.
     */
    private function renewHttpDate() {
        $time = time();
        $this->now = $time;
        $this->httpDateNow = gmdate('D, d M Y H:i:s', $time) . ' UTC';

        return $time;
    }

    /**
     * IMPORTANT: DO NOT REMOVE THE CALL TO unset(). It looks superfluous, but it's not. Keep-alive
     * timeout entries must be ordered by value. This means that it's not enough to replace the
     * existing map entry -- we have to remove it completely and push it back onto the end of the
     * array to maintain the correct order.
     */
    private function renewKeepAliveTimeout($socketId) {
        unset($this->keepAliveTimeouts[$socketId]);
        $this->keepAliveTimeouts[$socketId] = $this->now + $this->keepAliveTimeout;
    }

    private function onClient($socket, $isEncrypted) {
        stream_set_blocking($socket, FALSE);

        $socketId = (int) $socket;

        $client = new Client;
        $client->id = $socketId;
        $client->socket = $socket;
        $client->isEncrypted = $isEncrypted;

        $rawServerName = stream_socket_get_name($socket, FALSE);
        list($client->serverAddress, $client->serverPort) = $this->parseSocketName($rawServerName);

        $rawClientName = stream_socket_get_name($socket, TRUE);
        list($client->clientAddress, $client->clientPort) = $this->parseSocketName($rawClientName);

        $client->parser = $this->parserFactory->makeParser();
        $client->parser->setOptions([
            'maxHeaderBytes' => $this->maxHeaderBytes,
            'maxBodyBytes' => $this->maxBodyBytes,
            'returnBeforeEntity' => TRUE
        ]);

        $onReadable = function() use ($client) { $this->readClientSocketData($client); };
        $client->readWatcher = $this->reactor->onReadable($socket, $onReadable);

        $onWritable = function() use ($client) { $this->writePipelinedResponses($client); };
        $client->writeWatcher = $this->reactor->onWritable($socket, $onWritable, $enableNow = FALSE);

        $this->clients[$socketId] = $client;
    }

    private function parseSocketName($name) {
        // Make sure to use strrpos() instead of strpos() or we'll break IPv6 addresses
        $portStartPos = strrpos($name, ':');
        $address = substr($name, 0, $portStartPos);
        $port = substr($name, $portStartPos + 1);

        return [$address, $port];
    }

    private function readClientSocketData(Client $client) {
        $data = @fread($client->socket, $this->readGranularity);

        if ($data || $data === '0') {
            $this->renewKeepAliveTimeout($client->id);
            $this->parseClientSocketData($client, $data);
        } elseif (!is_resource($client->socket) || @feof($client->socket)) {
            $this->closeClient($client);
        }
    }

    private function parseClientSocketData(Client $client, $data) {
        try {
            while ($parsedRequest = $client->parser->parse($data)) {
                if ($parsedRequest['headersOnly']) {
                    $this->onPartialRequest($client, $parsedRequest);
                } else {
                    $this->onCompletedRequest($client, $parsedRequest);
                }

                if ($client->parser && $client->parser->getBuffer()) {
                    $data = '';
                } else {
                    break;
                }
            }
        } catch (ParseException $e) {
            $this->onParseError($client, $e);
        }
    }

    private function onParseError(Client $client, ParseException $e) {
        if ($client->partialMessageCycle) {
            $messageCycle = $client->partialMessageCycle;
            $client->partialMessageCycle = NULL;
        } else {
            $messageCycle = $this->initializeMessageCycle($client, $e->getParsedMsgArr());
        }

        $status = $e->getCode() ?: Status::BAD_REQUEST;
        $body = sprintf("<html><body><p>%s</p></body></html>", $e->getMessage());
        $messageCycle->response = new Response([
            'status' => $status,
            'body' => $body
        ]);

        $this->respond($messageCycle);
    }

    /**
     * @TODO Invoke Host application partial responders here (not yet implemented). These responders
     * (if present) should be used to answer request Expect headers (or whatever people wish to do
     * before the body arrives). The Server should allow generator multitasking at this stage.
     * If a Host's partial responder does not assign a
     *
     */
    private function onPartialRequest(Client $client, array $parsedRequest) {
        $messageCycle = $this->initializeMessageCycle($client, $parsedRequest);

        // @TODO Apply Host application partial responders here (not yet implemented)

        if (!$messageCycle->response && $messageCycle->expectsContinue) {
            $messageCycle->response = new Response([
                'status' => Status::CONTINUE_100,
                'reason' => Reason::HTTP_100
            ]);
        }

        // @TODO After responding to an expectation we probably need to modify the request parser's
        // state to avoid parse errors after a non-100 response. Otherwise we really have no choice
        // but to close the connection after this response.
        if ($messageCycle->response) {
            $this->respond($messageCycle);
        }
    }

    private function initializeMessageCycle(Client $client, array $parsedRequestMap) {
        extract($parsedRequestMap, $flags = EXTR_PREFIX_ALL, $prefix = '_');

        $__method = $this->normalizeMethodCase ? strtoupper($__method) : $__method;
        $__protocol = (!$__protocol || $__protocol === '?') ? '1.0' : $__protocol;

        $messageCycle = new MessageCycle;
        $messageCycle->requestId = ++$this->lastRequestId;
        $messageCycle->client = $client;
        $messageCycle->protocol = $__protocol;
        $messageCycle->isHttp11 = ($__protocol >= 1.1);
        $messageCycle->isHttp10 = ($__protocol == 1.0);
        $messageCycle->method = $__method;
        $messageCycle->body = $__body;
        $messageCycle->headers = $__headers;
        $messageCycle->ucHeaders = array_change_key_case($__headers, CASE_UPPER);
        $messageCycle->uri = $__uri;

        if (stripos($__uri, 'http://') === 0 || stripos($__uri, 'https://') === 0) {
            extract(parse_url($__uri, $flags = EXTR_PREFIX_ALL, $prefix = '__uri_'));
            $messageCycle->hasAbsoluteUri = TRUE;
            $messageCycle->uriHost = $__uri_host;
            $messageCycle->uriPort = $__uri_port;
            $messageCycle->uriPath = $__uri_path;
            $messageCycle->uriQuery = $__uri_query;
        } elseif ($qPos = strpos($__uri, '?')) {
            $messageCycle->uriQuery = substr($__uri, $qPos + 1);
            $messageCycle->uriPath = substr($__uri, 0, $qPos);
        } else {
            $messageCycle->uriPath = $__uri;
        }

        if (empty($messageCycle->ucHeaders['EXPECT'])) {
            $messageCycle->expectsContinue = FALSE;
        } elseif (stristr($messageCycle->ucHeaders['EXPECT'][0], '100-continue')) {
            $messageCycle->expectsContinue = TRUE;
        } else {
            $messageCycle->expectsContinue = FALSE;
        }

        $client->requestCount++;
        $client->messageCycles[$messageCycle->requestId] = $messageCycle;
        $client->partialMessageCycle = $__headersOnly ? $messageCycle : NULL;

        list($host, $isValidHost) = $this->hosts->selectHost($messageCycle, $this->defaultHost);
        $messageCycle->host = $host;

        $serverName = $host->hasName() ? $host->getName() : $client->serverAddress;

        // It's important to pull the $uriScheme from the encryption status of the client socket and
        // NOT the scheme parsed from the request URI as the request could have passed an erroneous
        // absolute https or http scheme in an absolute URI -or- a valid scheme that doesn't reflect
        // the encryption status of the client's connection to the server (when forward proxying).
        $uriScheme = $client->isEncrypted ? 'https' : 'http';

        $request = [
            'ASGI_VERSION'      => '0.1',
            'ASGI_NON_BLOCKING' => TRUE,
            'ASGI_ERROR'        => NULL, // @TODO Do we even need this in the environment?
            'ASGI_INPUT'        => $messageCycle->body,
            'ASGI_LAST_CHANCE'  => (bool) $messageCycle->body,
            'SERVER_PORT'       => $client->serverPort,
            'SERVER_ADDR'       => $client->serverAddress,
            'SERVER_NAME'       => $serverName,
            'SERVER_PROTOCOL'   => $messageCycle->protocol,
            'REMOTE_ADDR'       => $client->clientAddress,
            'REMOTE_PORT'       => $client->clientPort,
            'REQUEST_METHOD'    => $messageCycle->method,
            'REQUEST_URI'       => $messageCycle->uri,
            'REQUEST_URI_PATH'  => $messageCycle->uriPath,
            'REQUEST_URI_SCHEME'=> $uriScheme,
            'QUERY_STRING'      => $messageCycle->uriQuery
        ];

        $ucHeaders = $messageCycle->ucHeaders;

        if (!empty($ucHeaders['CONTENT-TYPE'])) {
            $request['CONTENT_TYPE'] = $ucHeaders['CONTENT-TYPE'][0];
            unset($ucHeaders['CONTENT-TYPE']);
        }

        if (!empty($ucHeaders['CONTENT-LENGTH'])) {
            $request['CONTENT_LENGTH'] = $ucHeaders['CONTENT-LENGTH'][0];
            unset($ucHeaders['CONTENT-LENGTH']);
        }

        if ($messageCycle->uriQuery) {
            parse_str($messageCycle->uriQuery, $request['QUERY']);
        }

        // @TODO Add cookie parsing
        //if (!empty($ucHeaders['COOKIE']) && ($cookies = $this->parseCookies($ucHeaders['COOKIE']))) {
        //    $request['COOKIE'] = $cookies;
        //}

        // @TODO Add multipart entity parsing

        foreach ($ucHeaders as $field => $value) {
            $field = 'HTTP_' . str_replace('-', '_', $field);
            $request[$field] = isset($value[1]) ? implode(',', $value) : $value[0];
        }

        $messageCycle->request = $request;

        if (!$isValidHost) {
            $messageCycle->response = new Response([
                'status' => Status::BAD_REQUEST,
                'reason' => 'Bad Request: Invalid Host',
                'body' => '<html><body><h1>400 Bad Request: Invalid Host</h1></body></html>'
            ]);
        } elseif (!in_array($__method, $this->allowedMethods)) {
            $messageCycle->response = new Response([
                'status' => Status::METHOD_NOT_ALLOWED,
                'reason' => Reason::HTTP_405,
                'headers' => ['Allow: ' . implode(',', $this->allowedMethods)]
            ]);
        } elseif ($__method === 'TRACE' && empty($messageCycle->ucHeaders['MAX_FORWARDS'])) {
            // @TODO Max-Forwards needs some additional server flag because that check shouldn't
            // be used unless the server is acting as a reverse proxy
            $messageCycle->response = new Response([
                'status' => Status::OK,
                'reason' => Reason::HTTP_200,
                'headers' => ['Content-Type: message/http'],
                'body' => $__trace
            ]);
        } elseif ($__method === 'OPTIONS' && $messageCycle->uri === '*') {
            $messageCycle->response = new Response([
                'status' => Status::OK,
                'reason' => Reason::HTTP_200,
                'headers' => ['Allow: ' . implode(',', $this->allowedMethods)]
            ]);
        } elseif ($this->requireBodyLength && $__headersOnly && empty($messageCycle->ucHeaders['CONTENT-LENGTH'])) {
            $messageCycle->response = new Response([
                'status' => Status::LENGTH_REQUIRED,
                'reason' => 'Length Required',
                'headers' => ['Connection: close']
            ]);
        }

        return $messageCycle;
    }

    private function onCompletedRequest(Client $client, array $parsedRequest) {
        unset($this->keepAliveTimeouts[$client->id]);

        if ($messageCycle = $client->partialMessageCycle) {
            $this->updateRequestAfterEntity($messageCycle, $parsedRequest['headers']);
        } else {
            $messageCycle = $this->initializeMessageCycle($client, $parsedRequest);
        }

        if ($messageCycle->response) {
            $this->respond($messageCycle);
        } else {
            $this->invokeHostApplication($messageCycle);
        }
    }

    private function updateRequestAfterEntity(MessageCycle $messageCycle, array $parsedHeadersArray) {
        $messageCycle->request['ASGI_LAST_CHANCE'] = TRUE;
        $messageCycle->client->partialMessageCycle = NULL;

        if ($needsNewRequestId = $messageCycle->expectsContinue) {
            $messageCycle->requestId = ++$this->lastRequestId;
            $messageCycle->client->messageCycles[$messageCycle->requestId] = $messageCycle;
        }

        if (isset($messageCycle->request['HTTP_TRAILERS'])) {
            $this->updateTrailerHeaders($messageCycle, $parsedHeadersArray);
        }

        $contentType = isset($messageCycle->request['CONTENT_TYPE'])
            ? $messageCycle->request['CONTENT_TYPE']
            : NULL;

        if (stripos($contentType, 'application/x-www-form-urlencoded') === 0) {
            $bufferedBody = stream_get_contents($messageCycle->body);
            parse_str($bufferedBody, $messageCycle->request['FORM']);
            rewind($this->body);
        }
    }

    /**
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.40
     */
    private function updateTrailerHeaders(MessageCycle $messageCycle, array $headers) {
        $ucHeaders = array_change_key_case($headers, CASE_UPPER);

        // The Host header is ignored in trailers to prevent unsanitized values from bypassing the
        // original safety check when headers are first processed. The other values are expressly
        // disallowed by RFC 2616 Section 14.40.
        $disallowedHeaders = ['HOST', 'TRANSFER-ENCODING', 'CONTENT-LENGTH', 'TRAILER'];
        foreach (array_keys($headers) as $field) {
            $ucField = strtoupper($field);
            if (!in_array($ucField, $disallowedHeaders)) {
                $value = $headers[$field];
                $value = isset($value[1]) ? implode(',', $value) : $value[0];
                $key = 'HTTP_' . str_replace('-', '_', $ucField);
                $messageCycle->request[$key] = $value;
            }
        }
    }

    private function invokeHostApplication(MessageCycle $messageCycle) {
        try {
            $request = $messageCycle->request;
            $responder = $messageCycle->host->getApplication();
            $response = $responder($request);
            $this->assignMessageCycleResponse($messageCycle, $response);
        } catch (\Exception $e) {
            $this->assignExceptionResponse($messageCycle, $e);
        }
    }

    private function assignMessageCycleResponse($messageCycle, $response) {
        if (is_scalar($response)) {
            $messageCycle->response = new Response([
                'body' => $response
            ]);
            $this->respond($messageCycle);
        } elseif ($response instanceof Response) {
            $messageCycle->response = $response;
            $this->respond($messageCycle);
        } elseif ($response instanceof \Generator) {
            $this->processGeneratorResponse($messageCycle, $response);
        } elseif (is_null($response)) {
            $messageCycle->response = new Response([
                'status' => Status::NOT_FOUND,
                'reason' => Reason::HTTP_404,
                'body' => '<html><body><h1>404 Not Found</h1></body></html>'
            ]);
            $this->respond($messageCycle);
        } else {
            $errMsg = $this->showErrors
                ? sprintf('<pre>Invalid response type: %s</pre>', gettype($response))
                : '<p>Something went terribly wrong</p>';
            $this->logError($errMsg);
            $messageCycle->response = new Response([
                'status' => Status::INTERNAL_SERVER_ERROR,
                'reason' => Reason::HTTP_500,
                'body' => "<html><body><h1>500 Internal Server Error</h1>{$errMsg}</body></html>"
            ]);
            $this->respond($messageCycle);
        }
    }

    private function processGeneratorResponse(MessageCycle $messageCycle, \Generator $generator) {
        $key = $generator->key();
        $value = $generator->current();

        if ($yieldableStruct = $this->getYieldable($key, $value, $messageCycle, $generator)) {
            list($callable, $args) = $yieldableStruct;
            call_user_func_array($callable, $args);
        } elseif (!$this->storeYieldableGroup($value, $messageCycle, $generator)
            && ($value instanceof Response || is_scalar($value))
        ) {
            $this->assignMessageCycleResponse($messageCycle, $value);
        }
    }

    private function getYieldable($key, $value, MessageCycle $messageCycle, \Generator $generator, $id = NULL) {
        if (is_callable($key)) {
            $value = is_array($value) ? $value : [$value];
            array_push($value, function($toSend) use ($messageCycle, $generator, $id) {
                $this->sendGeneratorResult($messageCycle, $generator, $toSend, $id);
            });
            $yieldableStruct = [$key, $value];
        } elseif (is_callable($value)) {
            $yieldableStruct = [$value, [function($toSend) use ($messageCycle, $generator, $id) {
                $this->sendGeneratorResult($messageCycle, $generator, $toSend, $id);
            }]];
        } else {
            $yieldableStruct = [];
        }

        return $yieldableStruct;
    }

    private function sendGeneratorResult(MessageCycle $messageCycle, \Generator $generator, $toSend, $id) {
        try {
            if (!$messageCycle->hasYieldGroup()) {
                $generator->send($toSend);
                $this->processGeneratorResponse($messageCycle, $generator);

            // @TODO
            //} elseif ($toSend instanceof \Generator) {

            } elseif ($result = $messageCycle->submitYieldGroupResult($id, $toSend)) {
                $generator->send($result);
                $this->processGeneratorResponse($messageCycle, $generator);
            }
        } catch (\Exception $e) {
            $this->assignExceptionResponse($messageCycle, $e);
        }
    }

    private function storeYieldableGroup($yieldArray, MessageCycle $messageCycle, \Generator $generator) {
        if (!($yieldArray && is_array($yieldArray))) {
            return FALSE;
        }

        $yieldGroup = [];
        foreach ($yieldArray as $id => $yieldDefinition) {
            if (!($yieldDefinition && is_array($yieldDefinition))) {
                return FALSE;
            }

            $key = array_shift($yieldDefinition);
            $value = $yieldDefinition;
            if ($yieldableStruct = $this->getYieldable($key, $value, $messageCycle, $generator, $id)) {
                $yieldGroup[$id] = $yieldableStruct;
            } else {
                return FALSE;
            }
        }

        $messageCycle->storeYieldGroup($yieldGroup);

        foreach ($yieldGroup as $yieldableStruct) {
            list($callable, $args) = $yieldableStruct;
            call_user_func_array($callable, $args);
        }

        return TRUE;
    }

    private function assignExceptionResponse(MessageCycle $messageCycle, \Exception $e) {
        $msg = $this->showErrors
            ? '<pre>' . $e->__toString() . '</pre>'
            : '<p>Something went terribly wrong</p>';
        $status = Status::INTERNAL_SERVER_ERROR;
        $reason = Reason::HTTP_500;
        $body = "<html><body><h1>{$status} {$reason}</h1><p>{$msg}</p></body></html>";
        $messageCycle->response = new Response([
            'status' => $status,
            'reason' => $reason,
            'body' => $body
        ]);
        $this->respond($messageCycle);
    }

    private function respond(MessageCycle $messageCycle) {
        // @TODO Retrieve and execute Host::getAfterResponder() here ...

        $client = $messageCycle->client;

        foreach ($client->messageCycles as $requestId => $messageCycle) {
            
            if (isset($client->pipeline[$requestId])) {
                continue;
            } elseif ($messageCycle->response) {
                $responseWriter = $this->generateResponseWriter($messageCycle);
                $client->pipeline[$requestId] = $responseWriter;
            } else {
                break;
            }
        }

        reset($client->messageCycles);

        $this->writePipelinedResponses($client);
    }

    private function generateResponseWriter(MessageCycle $messageCycle) {
        try {
            $request = $messageCycle->request;
            $response = $messageCycle->response;
            $forceClose = ($this->disableKeepAlive || $this->state === self::STOPPING);
            $requestsRemaining = $this->maxRequests > 0
                ? $this->maxRequests - $messageCycle->client->requestCount
                : NULL;

            $normalizationStruct = $this->responseNormalizer->normalize($response, $request, $options = [
                'defaultContentType' => $this->defaultContentType,
                'defaultTextCharset' => $this->defaultTextCharset,
                'serverToken' => $this->sendServerToken ? self::NAME : NULL,
                'autoReason' => $this->autoReasonPhrase,
                'keepAliveTimeout' => $this->keepAliveTimeout,
                'dateHeader' => $this->httpDateNow,
                'requestsRemaining' => $requestsRemaining,
                'forceClose' => $forceClose
            ]);

            list($rawHeaders, $messageCycle->closeAfterSend) = $normalizationStruct;

            $rw = $this->writerFactory->make($messageCycle->client->socket, $rawHeaders, $response['body']);

        } catch (\DomainException $e) {
            $this->assignExceptionResponse($messageCycle, $e);
            $rw = $this->generateResponseWriter($messageCycle);
        } finally {
            return $rw;
        }
    }

    private function writePipelinedResponses(Client $client) {
        try {
            foreach ($client->pipeline as $requestId => $responseWriter) {
                if (!$responseWriter) {
                    break;
                } elseif ($responseWriter->write()) {
                    $this->afterResponse($client, $requestId);
                    // writability watchers are disabled during afterResponse() processing as needed
                } else {
                    $this->reactor->enable($client->writeWatcher);
                    break;
                }
            }
        } catch (ResourceException $e) {
            $this->closeClient($client);
        }
    }

    private function afterResponse(Client $client, $requestId) {
        $messageCycle = $client->messageCycles[$requestId];

        if ($upgradeCallback = $this->getSocketUpgradeCallback($messageCycle)) {
            $this->upgradeSocket($upgradeCallback, $client, $messageCycle->request);
        } elseif ($messageCycle->closeAfterSend) {
            $this->closeClient($client);
        } else {
            $this->shiftClientPipeline($client, $requestId);
        }
    }

    private function getSocketUpgradeCallback(MessageCycle $messageCycle) {
        if ($messageCycle->response['status'] !== Status::SWITCHING_PROTOCOLS) {
            $upgradeCallback = NULL;
        } elseif (empty($messageCycle->response['export_callback'])) {
            $upgradeCallback = NULL;
        } elseif (($callable = $messageCycle->response['export_callback']) && is_callable($callable)) {
            $upgradeCallback = $callable;
        } else {
            $upgradeCallback = NULL;
        }

        return $upgradeCallback;
    }

    private function upgradeSocket(callable $upgradeCallback, Client $client, $request) {
        try {
            $this->clearClientReferences($client);

            // We increment the count here because it is decremented during the call to
            // clearClientReferences() and we want to keep track of the connected client
            // as long as it's still open.
            $this->cachedClientCount++;

            $socket = $client->socket;
            $socketId = (int) $socket;
            $this->exportedSocketIdMap[$socketId] = $socket;

            $upgradeCallback($socket, $request, $onClose = function() use ($socketId) {
                $this->closeExportedSocket($socketId);
            });
        } catch (\Exception $e) {
            $this->closeExportedSocket($socket);
            $this->logError($e);
        }
    }

    private function closeExportedSocket($socketId) {
        $socket = $this->exportedSocketIdMap[$socketId];
        $this->cachedClientCount--;
        $this->doSocketClose($socket);
        unset($this->exportedSocketIdMap[$socketId]);
    }

    private function clearClientReferences(Client $client) {
        $client->messageCycles = NULL;
        $this->reactor->cancel($client->readWatcher);
        $this->reactor->cancel($client->writeWatcher);
        $client->parser = NULL;
        $socketId = $client->id;

        unset(
            $this->clients[$socketId],
            $this->keepAliveTimeouts[$socketId]
        );

        $this->cachedClientCount--;

        if ($this->state === self::PAUSED
            && $this->maxConnections > 0
            && $this->cachedClientCount <= $this->maxConnections
        ) {
            $this->resumeClientAcceptance();
        }
    }

    private function closeClient(Client $client) {
        $this->clearClientReferences($client);
        $this->doSocketClose($client->socket);

        if ($this->state === self::STOPPING && !$this->clients) {
            $this->notifyObservers(self::NEED_STOP_PERMISSION);
        }
    }

    private function doSocketClose($socket) {
        if ($this->socketSoLingerZero) {
            $this->closeSocketWithSoLingerZero($socket);
        } elseif (is_resource($socket)) {
            stream_socket_shutdown($socket, STREAM_SHUT_WR);
            @fread($socket, $this->readGranularity);
            @fclose($socket);
        }
    }

    private function closeSocketWithSoLingerZero($socket) {
        if (isset(stream_context_get_options($socket)['ssl'])) {
            // ext/sockets can't import stream with crypto enabled
            @stream_socket_enable_crypto($socket, FALSE);
        }

        $socket = socket_import_stream($socket);
        socket_set_option($socket, SOL_SOCKET, SO_LINGER, [
            'l_onoff' => 1,
            'l_linger' => 0
        ]);

        socket_close($socket);
    }

    private function shiftClientPipeline($client, $requestId) {
        unset(
            $client->pipeline[$requestId],
            $client->messageCycles[$requestId]
        );

        // Disable active onWritable stream watchers if the pipeline is no longer write-ready
        if (!current($client->pipeline)) {
            $this->reactor->disable($client->writeWatcher);
            $this->renewKeepAliveTimeout($client->id);
        }
    }

    /**
     * A convenience method to simplify error logging in multiprocess environments
     *
     * To avoid data corruption the error log file must be locked during writes. Applications may
     * use this method as a convenience method to avoid locking/unlocking the error stream manually.
     *
     * Note that currently this method *WILL BLOCK* execution while it obtains a write lock. This
     * WILL SLOW DOWN YOUR SERVER, so don't have errors in your programs.
     *
     * @param string $errorMsg The error string to write (newline is auto-appended)
     * @return void
     * @TODO This should be dispatched when pthreads is available and block otherwise.
     */
    public function logError($errorMsg) {
        $errorMsg = trim($errorMsg) . PHP_EOL;

        if (@flock($this->errorStream, LOCK_EX)) {
            @fwrite($this->errorStream, $errorMsg);
            @fflush($this->errorStream);
            @flock($fp, LOCK_UN);
        } else {
            // @TODO Maybe? Not sure how to handle this right now ...
        }
    }

    /**
     * Set multiple server options at once
     *
     * @param array $options
     * @throws \DomainException On unrecognized option key
     * @return void
     */
    public function setAllOptions(array $options) {
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }
    }

    /**
     * Set an individual server option directive
     *
     * @param string $option The option key (case-INsensitve)
     * @param mixed $value The option value to assign
     * @throws \DomainException On unrecognized option key
     * @return void
     */
    public function setOption($option, $value) {
        switch (strtolower($option)) {
            case 'maxconnections':
                $this->setMaxConnections($value); break;
            case 'maxrequests':
                $this->setMaxRequests($value); break;
            case 'keepalivetimeout':
                $this->setKeepAliveTimeout($value); break;
            case 'disablekeepalive':
                $this->setDisableKeepAlive($value); break;
            case 'maxheaderbytes':
                $this->setMaxHeaderBytes($value); break;
            case 'maxbodybytes':
                $this->setMaxBodyBytes($value); break;
            case 'defaultcontenttype':
                $this->setDefaultContentType($value); break;
            case 'defaulttextcharset':
                $this->setDefaultTextCharset($value); break;
            case 'autoreasonphrase':
                $this->setAutoReasonPhrase($value); break;
            case 'errorstream':
                $this->setErrorStream($value); break;
            case 'sendservertoken':
                $this->setSendServerToken($value); break;
            case 'normalizemethodcase':
                $this->setNormalizeMethodCase($value); break;
            case 'requirebodylength':
                $this->setRequireBodyLength($value); break;
            case 'socketsolingerzero':
                $this->setSocketSoLingerZero($value); break;
            case 'socketbacklogsize':
                $this->setSocketBacklogSize($value); break;
            case 'allowedmethods':
                $this->setAllowedMethods($value); break;
            case 'defaulthost':
                $this->setDefaultHost($value); break;
            case 'showerrors':
                $this->setShowErrors($value); break;
            case 'stoptimeout':
                $this->setStopTimeout($value); break;
            default:
                throw new \DomainException(
                    "Unknown server option: {$option}"
                );
        }
    }

    private function setMaxConnections($maxConns) {
        $this->maxConnections = (int) $maxConns;
    }

    private function setMaxRequests($maxRequests) {
        $this->maxRequests = (int) $maxRequests;
    }

    private function setKeepAliveTimeout($seconds) {
        $this->keepAliveTimeout = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => -1,
            'default' => 10
        ]]);
    }

    private function setDisableKeepAlive($boolFlag) {
        $this->disableKeepAlive = (bool) $boolFlag;
    }

    private function setMaxHeaderBytes($bytes) {
        $this->maxHeaderBytes = (int) $bytes;
    }

    private function setMaxBodyBytes($bytes) {
        $this->maxBodyBytes = (int) $bytes;
    }

    private function setDefaultContentType($mimeType) {
        $this->defaultContentType = $mimeType;
    }

    private function setDefaultTextCharset($charset) {
        $this->defaultTextCharset = $charset;
    }

    private function setAutoReasonPhrase($boolFlag) {
        $this->autoReasonPhrase = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }

    private function setErrorStream($pathOrResource) {
        if (is_resource($pathOrResource)) {
            $this->errorStream = $pathOrResource;
            $this->errorLogPath = stream_get_meta_data($pathOrResource)['uri'];
        } elseif (is_string($pathOrResource)) {
            $this->errorStream = fopen($pathOrResource, 'a+');
            $this->errorLogPath = $pathOrResource;
        }
    }

    private function setSendServerToken($boolFlag) {
        $this->sendServerToken = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }

    private function setNormalizeMethodCase($boolFlag) {
        $this->normalizeMethodCase = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }

    private function setRequireBodyLength($boolFlag) {
        $this->requireBodyLength = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }

    private function setSocketSoLingerZero($boolFlag) {
        $boolFlag = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);

        if ($boolFlag && !$this->isExtSocketsEnabled) {
            throw new \RuntimeException(
                'Cannot enable socketSoLingerZero; PHP sockets extension required'
            );
        }

        $this->socketSoLingerZero = $boolFlag;
    }

    private function setSocketBacklogSize($backlogSize) {
        $this->hostBinder->setSocketBacklogSize($backlogSize);
    }

    private function setAllowedMethods(array $methods) {
        if (!in_array('GET', $methods)) {
            $methods[] = 'GET';
        }

        if (!in_array('HEAD', $methods)) {
            $methods[] = 'HEAD';
        }

        $this->allowedMethods = $methods;
    }

    private function setDefaultHost($hostId) {
        $this->defaultHost = $hostId;
    }

    private function setShowErrors($boolFlag) {
        $this->showErrors = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }

    private function setStopTimeout($timeoutInSeconds) {
        $this->timeout = filter_var($timeoutInSeconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => -1,
            'default' => -1
        ]]);
    }

    /**
     * Retrieve a server option value
     *
     * @param string $option The (case-insensitive) server option key
     * @throws \DomainException On unknown option
     * @return mixed The value of the requested server option
     */
    public function getOption($option) {
        switch (strtolower($option)) {
            case 'maxconnections':
                return $this->maxConnections; break;
            case 'maxrequests':
                return $this->maxRequests; break;
            case 'keepalivetimeout':
                return $this->keepAliveTimeout; break;
            case 'disablekeepalive':
                return $this->disableKeepAlive; break;
            case 'maxheaderbytes':
                return $this->maxHeaderBytes; break;
            case 'maxbodybytes':
                return $this->maxBodyBytes; break;
            case 'defaultcontenttype':
                return $this->defaultContentType; break;
            case 'defaulttextcharset':
                return $this->defaultTextCharset; break;
            case 'autoreasonphrase':
                return $this->autoReasonPhrase; break;
            case 'errorstream':
                return $this->errorStream; break;
            case 'sendservertoken':
                return $this->sendServerToken; break;
            case 'normalizemethodcase':
                return $this->normalizeMethodCase; break;
            case 'requirebodylength':
                return $this->requireBodyLength; break;
            case 'socketsolingerzero':
                return $this->socketSoLingerZero; break;
            case 'socketbacklogsize':
                return $this->hostBinder->getSocketBacklogSize(); break;
            case 'allowedmethods':
                return $this->allowedMethods; break;
            case 'defaulthost':
                return $this->defaultHost; break;
            case 'showerrors':
                return $this->showErrors; break;
            case 'stoptimeout':
                return $this->stopTimeout; break;
            default:
                throw new \DomainException(
                    "Unknown server option: {$option}"
                );
        }
    }

    /**
     * Retrieve an associative array mapping all option keys to their current values
     *
     * @return array
     */
    public function getAllOptions() {
        return [
            'maxConnections'        => $this->maxConnections,
            'maxRequests'           => $this->maxRequests,
            'keepAliveTimeout'      => $this->keepAliveTimeout,
            'disableKeepAlive'      => $this->disableKeepAlive,
            'maxHeaderBytes'        => $this->maxHeaderBytes,
            'maxBodyBytes'          => $this->maxBodyBytes,
            'defaultContentType'    => $this->defaultContentType,
            'defaultTextCharset'    => $this->defaultTextCharset,
            'autoReasonPhrase'      => $this->autoReasonPhrase,
            'errorStream'           => $this->errorLogPath,
            'sendServerToken'       => $this->sendServerToken,
            'normalizeMethodCase'   => $this->normalizeMethodCase,
            'requireBodyLength'     => $this->requireBodyLength,
            'socketSoLingerZero'    => $this->socketSoLingerZero,
            'socketBacklogSize'     => $this->hostBinder->getSocketBacklogSize(),
            'allowedMethods'        => $this->allowedMethods,
            'defaultHost'           => $this->defaultHost,
            'showErrors'            => $this->showErrors,
            'stopTimeout'           => $this->stopTimeout
        ];
    }

    public function __destruct() {
        foreach ($this->acceptWatchers as $watcherId) {
            $this->reactor->cancel($watcherId);
        }

        foreach ($this->pendingTlsWatchers as $watcherId) {
            $this->reactor->cancel($watcherId);
        }

        if ($this->keepAliveWatcher) {
            $this->reactor->cancel($this->keepAliveWatcher);
        }

        if ($this->forceStopWatcher) {
            $this->reactor->cancel($this->forceStopWatcher);
        }
    }
}
