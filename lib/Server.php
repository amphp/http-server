<?php

namespace Aerys;

use Alert\Reactor,
    After\Promise,
    After\Future,
    After\Success,
    After\Aggregate;

class Server {
    const NAME = 'Aerys/0.1.0-devel';
    const VERSION = '0.1.0-dev';

    const STOPPED = 0;
    const STARTING = 1;
    const STARTED = 2;
    const PAUSED = 3;
    const STOPPING = 4;

    const OP_MAX_CONNECTIONS = 1;
    const OP_MAX_REQUESTS = 2;
    const OP_KEEP_ALIVE_TIMEOUT = 3;
    const OP_DISABLE_KEEP_ALIVE = 4;
    const OP_MAX_HEADER_BYTES = 5;
    const OP_MAX_BODY_BYTES = 6;
    const OP_DEFAULT_CONTENT_TYPE = 7;
    const OP_DEFAULT_TEXT_CHARSET = 8;
    const OP_AUTO_REASON_PHRASE = 9;
    const OP_SEND_SERVER_TOKEN = 10;
    const OP_NORMALIZE_METHOD_CASE = 11;
    const OP_REQUIRE_BODY_LENGTH = 12;
    const OP_SOCKET_SO_LINGER_ZERO = 13;
    const OP_SOCKET_BACKLOG_SIZE = 14;
    const OP_ALLOWED_METHODS = 15;
    const OP_DEFAULT_HOST = 16;

    private $state;
    private $reactor;
    private $hostBinder;
    private $debug;
    private $stopPromise;
    private $observers;

    private $hosts;
    private $listeningSockets = [];
    private $acceptWatchers = [];
    private $pendingTlsWatchers = [];
    private $clients = [];
    private $exportedSocketIdMap = [];
    private $cachedClientCount = 0;
    private $lastRequestId;

    private $now;
    private $httpDateNow;
    private $httpDateFormat = 'D, d M Y H:i:s';
    private $keepAliveWatcher;
    private $keepAliveTimeouts = [];

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
    private $allowedMethods;
    private $cryptoType = STREAM_CRYPTO_METHOD_TLS_SERVER; // @TODO Add option setter
    private $readGranularity = 262144; // @TODO Add option setter
    private $isExtSocketsEnabled;

    public function __construct(Reactor $reactor, HostBinder $hb = NULL, $debug = FALSE) {
        $this->reactor = $reactor;
        $this->hostBinder = $hb ?: new HostBinder;
        $this->debug = (bool) $debug;
        $this->observers = new \SplObjectStorage;
        $this->isExtSocketsEnabled = extension_loaded('sockets');
        $this->lastRequestId = PHP_INT_MAX * -1; // <-- 5.5 compatibility
        $this->state = self::STOPPED;
        $this->allowedMethods = [
            'GET' => 1,
            'HEAD' => 1,
            'OPTIONS' => 1,
            'TRACE' => 1,
            'PUT' => 1,
            'POST' => 1,
            'PATCH' => 1,
            'DELETE' => 1,
        ];
    }

    /**
     * Attach a server event observer
     *
     * @param \Aerys\ServerObserver $observer
     * @return \Aerys\Server Returns the current object instance
     */
    public function addObserver(ServerObserver $observer) {
        $this->observers->attach($observer);

        return $this;
    }

    private function notifyObservers($event) {
        $futures = [];
        foreach ($this->observers as $observer) {
            $result = $observer->onServerUpdate($this, $event, NULL);
            if ($result instanceof Future) {
                $futures[] = $result;
            } elseif (isset($result)) {
                // @TODO Log error?
            }
        }

        return $futures ? Aggregate::all($futures) : new Success;
    }

    /**
     * Start the server
     *
     * IMPORTANT: The server's event reactor must still be started externally.
     *
     * @param mixed $hosts A Host, HostCollection or array of Host instances
     * @param array $listeningSockets Optional array mapping bind addresses to existing sockets
     * @throws \LogicException If no hosts have been added to the specified collection
     * @throws \RuntimeException On socket bind failure
     * @return \After\Future Returns a Future that will resolve once all startup tasks complete
     */
    public function start($hosts, array $listeningSockets = []) {
        if ($this->state !== self::STOPPED) {
            throw new \LogicException(
                'Server already started'
            );
        }

        $this->hosts = $this->normalizeStartHosts($hosts);
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

            // Don't enable these watchers now -- wait until start observers report completion
            $acceptWatcher = $this->reactor->onReadable($serverSocket, $acceptCallback, $enabled = FALSE);
            $this->acceptWatchers[$bindAddress] = $acceptWatcher;
        }

        $this->state = self::STARTING;
        $startFuture = $this->notifyObservers(self::STARTING);
        $startFuture->onResolution(function($future) { $this->onStartCompletion($future); });

        return $startFuture;
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

    private function onStartCompletion($future) {
        if ($future->succeeded()) {
            foreach ($this->acceptWatchers as $acceptWatcher) {
                $this->reactor->enable($acceptWatcher);
            }
            $this->renewHttpDate();
            $this->keepAliveWatcher = $this->reactor->repeat(function() {
                $this->timeoutKeepAlives();
            }, $msInterval = 1000);

            $this->state = self::STARTED;
        } else {
            // @TODO Log $future->getError();
            $this->stop();
        }
    }

    /**
     * Stop the server (gracefully)
     *
     * The server will take as long as necessary to complete the currently outstanding requests.
     * New client acceptance is suspended, previously assigned responses are sent in full and any
     * currently unfulfilled requests receive a 503 Service Unavailable response.
     *
     * @return \After\Future Returns a Future that will resolve once the shutdown routine completes.
     *                       If the server is already started no action occurs and NULL is returned.
     */
    public function stop() {
        if ($this->state === self::STOPPING) {
            $this->stopPromise->getFuture();
        } elseif ($this->state === self::STOPPED) {
            return new Success;
        }

        foreach ($this->acceptWatchers as $watcherId) {
            $this->reactor->cancel($watcherId);
        }

        foreach ($this->pendingTlsWatchers as $client) {
            $this->failTlsConnection($client);
        }

        // Do we want to do this? It could break partially received in-progress requests ...
        foreach ($this->clients as $client) {
            @stream_socket_shutdown($client->socket, STREAM_SHUT_RD);
        }

        $observerFuture = $this->notifyObservers(self::STOPPING);

        // If no clients are connected we need only resolve the observer future
        if (empty($this->clients)) {
            $stopFuture = $observerFuture;
        } else {
            $this->stopPromise = new Promise;
            $response = (new Response)
                ->setStatus(Status::SERVICE_UNAVAILABLE)
                ->setHeader('Connection', 'close')
                ->setBody('<html><body><h1>503 Service Unavailable</h1></body></html>')
            ;

            foreach ($this->clients as $client) {
                $this->stopClient($client, $response);
            }

            $stopFuture = Aggregate::all([$this->stopPromise->getFuture(), $observerFuture]);
        }

        $stopFuture->onResolution(function(Future $f) { $this->onStopCompletion($f); });

        return $stopFuture;
    }

    private function stopClient($client, Response $response) {
        if ($client->cycles) {
            $unassignedRequestIds = array_keys(array_diff_key($client->cycles, $client->pipeline));
            foreach ($unassignedRequestIds as $requestId) {
                $cycle = $client->cycles[$requestId];
                $cycle->response = $response;
                $this->hydrateWriterPipeline($cycle);
            }
        } else {
            $this->closeClient($client);
        }
    }

    private function onStopCompletion(Future $f) {
        $this->stopPromise = NULL;
        $this->reactor->cancel($this->keepAliveWatcher);
        $this->acceptWatchers = [];
        $this->listeningSockets = [];
        $this->state = self::STOPPED;

        if (!$f->succeeded()) {
            // @TODO Log $f->getError()
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
        $handshakeSucceeded = @stream_socket_enable_crypto($client, TRUE, $this->cryptoType);
        if ($handshakeSucceeded) {
            $this->clearPendingTlsClient($client);
            $this->onClient($client, $isEncrypted = TRUE);
        } elseif ($handshakeSucceeded === FALSE) {
            $this->failTlsConnection($client);
        }
    }

    private function failTlsConnection($client) {
        $this->cachedClientCount--;
        $this->clearPendingTlsClient($client);
        if (is_resource($client)) {
            @fclose($client);
        }
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

        $clientName = stream_socket_get_name($socket, TRUE);
        $serverName = stream_socket_get_name($socket, FALSE);
        list($client->clientAddress, $client->clientPort) = $this->parseSocketName($clientName);
        list($client->serverAddress, $client->serverPort) = $this->parseSocketName($serverName);

        $client->parser = (new Parser)->setOptions([
            'maxHeaderBytes' => $this->maxHeaderBytes,
            'maxBodyBytes' => $this->maxBodyBytes,
            'returnBeforeEntity' => TRUE
        ]);

        $onReadable = function() use ($client) { $this->readClientSocketData($client); };
        $client->readWatcher = $this->reactor->onReadable($socket, $onReadable);

        $onWritable = function() use ($client) { $client->pendingWriter->writeResponse(); };
        $client->writeWatcher = $this->reactor->onWritable($socket, $onWritable, $enableNow = FALSE);

        $this->clients[$socketId] = $client;
    }

    private function parseSocketName($name) {
        // IMPORTANT: use strrpos() instead of strpos() or we'll break IPv6 addresses
        $portStartPos = strrpos($name, ':');
        $address = substr($name, 0, $portStartPos);
        $port = substr($name, $portStartPos + 1);

        return [$address, $port];
    }

    private function readClientSocketData($client) {
        $data = @fread($client->socket, $this->readGranularity);
        if ($data || $data === '0') {
            $this->renewKeepAliveTimeout($client->id);
            $this->parseClientSocketData($client, $data);
        } elseif (!is_resource($client->socket) || @feof($client->socket)) {
            $this->closeClient($client);
        }
    }

    private function parseClientSocketData($client, $data) {
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
        } catch (ParserException $e) {
            $this->onParseError($client, $e);
        }
    }

    private function onParseError($client, ParserException $e) {
        if ($client->partialCycle) {
            $cycle = $client->partialCycle;
            $client->partialCycle = NULL;
        } else {
            $cycle = $this->initializeCycle($client, $e->getParsedMsgArr());
        }

        $status = $e->getCode() ?: Status::BAD_REQUEST;
        $body = sprintf("<html><body><p>%s</p></body></html>", $e->getMessage());
        $cycle->response = (new Response)->setStatus($status)->setBody($body);
        $this->hydrateWriterPipeline($cycle);
    }

    /**
     * @TODO Invoke Host application partial responders here (not yet implemented). These responders
     * (if present) should be used to answer request Expect headers (or whatever people wish to do
     * before the body arrives).
     *
     * @TODO Support generator multitasking in partial responders
     */
    private function onPartialRequest($client, array $parsedRequest) {
        $cycle = $this->initializeCycle($client, $parsedRequest);

        // @TODO Apply Host application partial responders here (not yet implemented)

        if (!$cycle->response && $cycle->expectsContinue) {
            $cycle->response = (new Response)->setStatus(Status::CONTINUE_100);
        }

        // @TODO After responding to an expectation we probably need to modify the request parser's
        // state to avoid parse errors after a non-100 response. Otherwise we really have no choice
        // but to close the connection after this response.
        if ($cycle->response) {
            $this->hydrateWriterPipeline($cycle);
        }
    }

    private function initializeCycle($client, array $parsedRequestMap) {
        extract($parsedRequestMap, $flags = EXTR_PREFIX_ALL, $prefix = '_');

        //$__protocol
        //$__method
        //$__uri
        //$__headers
        //$__body
        //$__trace
        //$__headersOnly

        if (empty($__protocol) || $__protocol === '?') {
            $__protocol = '1.0';
        }

        $__method = $this->normalizeMethodCase ? strtoupper($__method) : $__method;

        $cycle = new Cycle;
        $cycle->requestId = ++$this->lastRequestId;
        $cycle->client = $client;
        $cycle->protocol = $__protocol;
        $cycle->method = $__method;
        $cycle->body = $__body;
        $cycle->headers = $__headers;
        $cycle->uri = $__uri;

        if (stripos($__uri, 'http://') === 0 || stripos($__uri, 'https://') === 0) {
            extract(parse_url($__uri, $flags = EXTR_PREFIX_ALL, $prefix = '__uri_'));
            $cycle->hasAbsoluteUri = TRUE;
            $cycle->uriHost = $__uri_host;
            $cycle->uriPort = $__uri_port;
            $cycle->uriPath = $__uri_path;
            $cycle->uriQuery = $__uri_query;
        } elseif ($qPos = strpos($__uri, '?')) {
            $cycle->uriQuery = substr($__uri, $qPos + 1);
            $cycle->uriPath = substr($__uri, 0, $qPos);
        } else {
            $cycle->uriPath = $__uri;
        }

        if (empty($__headers['EXPECT'])) {
            $cycle->expectsContinue = FALSE;
        } elseif (stristr($__headers['EXPECT'][0], '100-continue')) {
            $cycle->expectsContinue = TRUE;
        } else {
            $cycle->expectsContinue = FALSE;
        }

        $client->requestCount++;
        $client->cycles[$cycle->requestId] = $cycle;
        $client->partialCycle = $__headersOnly ? $cycle : NULL;

        list($host, $isValidHost) = $this->hosts->selectHost($cycle, $this->defaultHost);
        $cycle->host = $host;

        $serverName = $host->hasName() ? $host->getName() : $client->serverAddress;
        if ($serverName === '*') {
            $sp = $client->serverPort;
            $serverNamePort = ($sp == 80 || $sp == 443) ? '' : ":{$sp}";
            $serverName = $client->serverAddress . $serverNamePort;
        }

        $request = [
            'ASGI_VERSION'      => '0.1',
            'ASGI_NON_BLOCKING' => TRUE,
            'ASGI_ERROR'        => NULL,
            'ASGI_INPUT'        => $cycle->body,
            'AERYS_SOCKET_ID'   => $client->id,
            'SERVER_PORT'       => $client->serverPort,
            'SERVER_ADDR'       => $client->serverAddress,
            'SERVER_NAME'       => $serverName,
            'SERVER_PROTOCOL'   => $cycle->protocol,
            'REMOTE_ADDR'       => $client->clientAddress,
            'REMOTE_PORT'       => $client->clientPort,
            'HTTPS'             => $client->isEncrypted,
            'REQUEST_METHOD'    => $cycle->method,
            'REQUEST_URI'       => $cycle->uri,
            'REQUEST_URI_PATH'  => $cycle->uriPath,
            'QUERY_STRING'      => $cycle->uriQuery
        ];

        if (!empty($__headers['CONTENT-TYPE'])) {
            $request['CONTENT_TYPE'] = $__headers['CONTENT-TYPE'][0];
            unset($__headers['CONTENT-TYPE']);
        }

        if (!empty($__headers['CONTENT-LENGTH'])) {
            $request['CONTENT_LENGTH'] = $__headers['CONTENT-LENGTH'][0];
            unset($__headers['CONTENT-LENGTH']);
        }

        $request['QUERY'] = $cycle->uriQuery ? parse_str($cycle->uriQuery, $request['QUERY']) : [];

        // @TODO Add cookie parsing
        //if (!empty($headers['COOKIE']) && ($cookies = $this->parseCookies($headers['COOKIE']))) {
        //    $request['COOKIE'] = $cookies;
        //}

        // @TODO Add multipart entity parsing

        foreach ($__headers as $field => $value) {
            $field = 'HTTP_' . str_replace('-', '_', $field);
            $value = isset($value[1]) ? implode(',', $value) : $value[0];
            $request[$field] = $value;
        }

        $cycle->request = $request;

        if (!$isValidHost) {
            $cycle->response = (new Response)
                ->setStatus(Status::BAD_REQUEST)
                ->setReason('Bad Request: Invalid Host')
                ->setBody('<html><body><h1>400 Bad Request: Invalid Host</h1></body></html>')
            ;
        } elseif (!isset($this->allowedMethods[$__method])) {
            $cycle->response = (new Response)
                ->setStatus(Status::METHOD_NOT_ALLOWED)
                ->setHeader('Allow', implode(',', array_keys($this->allowedMethods)))
                ->setHeader('Connection', 'close')
            ;
        } elseif ($__method === 'TRACE' && empty($cycle->headers['MAX_FORWARDS'])) {
            // @TODO Max-Forwards needs some additional server flag because that check shouldn't
            // be used unless the server is acting as a reverse proxy
            $cycle->response = (new Response)
                ->setStatus(Status::OK)
                ->setHeader('Content-Type', 'message/http')
                ->setBody($__trace)
            ;
        } elseif ($__method === 'OPTIONS' && $cycle->uri === '*') {
            $cycle->response = (new Response)
                ->setStatus(Status::OK)
                ->setHeader('Allow', implode(',', array_keys($this->allowedMethods)))
            ;
        } elseif ($this->requireBodyLength && $__headersOnly && empty($cycle->headers['CONTENT-LENGTH'])) {
            $cycle->response = (new Response)
                ->setStatus(Status::LENGTH_REQUIRED)
                ->setReason('Content Length Required')
                ->setHeader('Connection', 'close')
            ;
        }

        return $cycle;
    }

    private function onCompletedRequest($client, array $parsedRequest) {
        unset($this->keepAliveTimeouts[$client->id]);

        if ($cycle = $client->partialCycle) {
            $this->updateRequestAfterEntity($cycle, $parsedRequest['headers']);
        } else {
            $cycle = $this->initializeCycle($client, $parsedRequest);
        }

        if ($cycle->response) {
            $this->hydrateWriterPipeline($cycle);
        } else {
            $this->invokeHostApplication($cycle);
        }
    }

    private function updateRequestAfterEntity($cycle, array $parsedHeadersArray) {
        $cycle->client->partialCycle = NULL;

        if ($needsNewRequestId = $cycle->expectsContinue) {
            $cycle->requestId = ++$this->lastRequestId;
            $cycle->client->cycles[$cycle->requestId] = $cycle;
        }

        if (isset($cycle->request['HTTP_TRAILERS'])) {
            $this->updateTrailerHeaders($cycle, $parsedHeadersArray);
        }

        $contentType = isset($cycle->request['CONTENT_TYPE'])
            ? $cycle->request['CONTENT_TYPE']
            : NULL;

        if (stripos($contentType, 'application/x-www-form-urlencoded') === 0) {
            $bufferedBody = stream_get_contents($cycle->body);
            parse_str($bufferedBody, $cycle->request['FORM']);
            rewind($cycle->body);
        }
    }

    /**
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.40
     */
    private function updateTrailerHeaders($cycle, array $headers) {
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
                $cycle->request[$key] = $value;
            }
        }
    }

    private function invokeHostApplication($cycle) {
        try {
            $application = $cycle->host->getApplication();
            $response = $application($cycle->request);
            $this->assignResponse($cycle, $response);
        } catch (\Exception $exception) {
            $this->assignExceptionResponse($cycle, $exception);
        }
    }

    private function assignResponse($cycle, $response) {
        if (is_string($response)) {
            $cycle->response = (new Response)->setBody($response);
            $this->hydrateWriterPipeline($cycle);
        } elseif ($response instanceof Response || $response instanceof ResponseWriterCustom) {
            $cycle->response = $response;
            $this->hydrateWriterPipeline($cycle);
        } elseif ($response instanceof \Generator) {
            $this->advanceResponseGenerator($cycle, $response);
        } elseif ($response instanceof Future) {
            $response->onResolution(function($future) use ($cycle) {
                $this->onFutureResponseResolution($cycle, $future);
            });
        } else {
            $this->assignExceptionResponse($cycle, new \UnexpectedValueException(
                sprintf("Unexpected response type: %s", gettype($response))
            ));
        }
    }

    private function advanceResponseGenerator($cycle, $generator) {
        try {
            $yielded = $generator->current();

            if ($yielded instanceof Future) {
                // If the generator yields a Future we wait until it's resolved to
                // determine how to proceed.
                $yielded->onResolution(function($future) use ($cycle, $generator) {
                    $this->onFutureYieldResolution($cycle, $generator, $future);
                });
            } elseif (is_array($yielded)) {
                // @TODO Catch or throw an appropriate exception here to ease debugging.
                // Currently the PromiseGroup will throw it's own thing. This could make
                // debugging difficult for users.

                // Any yielded array MUST be an array of Future values. If so, we
                // send the results back to the generator upon completion. Otherwise
                // the group instantiation will throw and an appropriate error
                // response will be sent.
                $multiFuture = Aggregate::all($yielded);
                $multiFuture->onResolution(function($future) use ($cycle, $generator) {
                    $this->onFutureYieldResolution($cycle, $generator, $future);
                });
            } elseif ($yielded instanceof Response || $yielded instanceof ResponseWriterCustom) {
                $cycle->response = $yielded;
                $this->hydrateWriterPipeline($cycle);
            } elseif (is_string($yielded)) {
                // If the generator yields a string we assume that all remaining values
                // yielded from the generator will be strings or resolvable futures.
                // A streaming response is assumed and the generator is its body.
                $cycle->response = (new Response)->setBody($generator);
                $this->hydrateWriterPipeline($cycle);
            } else {
                throw new \UnexpectedValueException(
                    sprintf("Unexpected yield type: %s", gettype($yielded))
                );
            }
        } catch (\Exception $e) {
            $this->assignExceptionResponse($cycle, $e);
        }
    }

    private function onFutureYieldResolution($cycle, $generator, $future) {
        try {
            if ($cycle->client->isGone) {
                // Client disconnected while we were resolving the response;
                // nothing to do -- GC will take over from here.
            } elseif ($future->succeeded()) {
                $resolvedValue = $future->getValue();
                $generator->send($resolvedValue);
                $this->advanceResponseGenerator($cycle, $generator);
            } else {
                $generator->throw($future->getError());
                $this->advanceResponseGenerator($cycle, $generator);
            }
        } catch (\Exception $e) {
            $this->assignExceptionResponse($cycle, $e);
        }
    }

    private function onFutureResponseResolution($cycle, $future) {
        if ($cycle->client->isGone) {
            // Client disconnected while we were resolving the response;
            // nothing to do -- GC will take over from here.
        } elseif ($future->succeeded()) {
            $this->assignResponse($cycle, $future->getValue());
        } else {
            $this->assignExceptionResponse($cycle, $future->getError());
        }
    }

    private function assignExceptionResponse($cycle, \Exception $exception) {
        if (!$this->debug) {
            // @TODO Log the error here. For now we'll just send it to STDERR:
            @fwrite(STDERR, $exception);
        }

        $displayMsg = $this->debug
            ? "<pre>{$exception}</pre>"
            : '<p>Something went terribly wrong</p>';

        $status = Status::INTERNAL_SERVER_ERROR;
        $reason = Reason::HTTP_500;
        $body = "<html><body><h1>{$status} {$reason}</h1><p>{$displayMsg}</p></body></html>";
        $cycle->response = (new Response)->setStatus($status)->setReason($reason)->setBody($body);

        $this->hydrateWriterPipeline($cycle);
    }

    private function hydrateWriterPipeline($cycle) {
        // @TODO Retrieve and execute Host::getBeforeResponse() here ...

        $client = $cycle->client;

        foreach ($client->cycles as $requestId => $cycle) {
            if (isset($client->pipeline[$requestId])) {
                continue;
            } elseif ($cycle->response) {
                $client->pipeline[$requestId] = $this->makeResponseWriter($cycle);
            } else {
                break;
            }
        }

        // IMPORTANT: don't break the pipeline order!
        reset($client->cycles);

        if ($client->pendingWriter || !($writer = current($client->pipeline))) {
            // If we already have a pending writer in progress we have to wait until
            // it's complete before starting the next one. Otherwise we need to ensure
            // the first request in the pipeline has a writer before proceeding.
            return;
        }

        $writeResult = $writer->writeResponse();

        if ($writeResult === (bool) $writeResult) {
            // A boolean return indicates the write operation is complete. This is more
            // performant than going through the Future interface, so Writer implementations
            // should return a bool immediately if they're finished sending the response.
            $this->afterResponseWrite($client, $writeResult);
        } elseif ($writeResult instanceof Future) {
            // IMPORTANT: Client writability watchers map to the client's pendingWriter
            // property. By always storing the currently in-progress writer in this property
            // we're able to reuse the same writability watcher for the full life of the
            // client connection. This is a big performance win and any refactoring should
            // retain this "single-watcher" approach.
            $client->pendingWriter = $writer;

            // The $writeResult is a future value; here we assign the appropriate callback to
            // invoke when the future eventually resolves.
            $writeResult->onResolution(function($future) use ($client) {
                $this->onFutureWriteResolution($client, $future);
            });
        } else {
            // @TODO this represents a clear program error as Writers are expected
            // to return either an integer close flag or a Future that resolves with
            // an integer close flag upon success. However, it may be preferable to send
            // an error response instead of killing the server with an uncaught exception.
            throw new \UnexpectedValueException(
                'Unexpected response writer result; boolean or Future required.'
            );
        }
    }

    /**
     * Yes, this could be broken out into multiple methods to improve readability and eliminate
     * the inline comments. In fact, it originally was separated into several methods. However,
     * the "one long method" approach is used specifically here to minimize expensive additional
     * userland function calls on each response in the interest of performance maximization.
     *
     * @return Aerys\ResponseWriter
     */
    private function makeResponseWriter($cycle) {
        $client = $cycle->client;
        $request = $cycle->request;
        $response = $cycle->response;

        // --- Perform standard normalizations required for both Writer and WriterCustom -----------

        if ($this->disableKeepAlive || $this->state === self::STOPPING) {
            // If keep-alive is disabled or the server is stopping we always close
            // after the response is written.
            $mustClose = TRUE;
        } elseif ($this->maxRequests > 0 && $client->requestCount >= $this->maxRequests) {
            // If the client has exceeded the max allowable requests per connection
            // we always close after the response is written.
            $mustClose = TRUE;
        } elseif (isset($request['HTTP_CONNECTION'])) {
            // If the request indicated a close preference we agree to that. If the request uses
            // HTTP/1.0 we may still have to close if the response content length is unknown.
            // This potential need must be determined based on whether or not the response
            // content length is known at the time output starts.
            $mustClose = (stripos($request['HTTP_CONNECTION'], 'close') !== FALSE);
        } elseif ($request['SERVER_PROTOCOL'] < 1.1) {
            // HTTP/1.0 defaults to a close after each response if not otherwise specified.
            $mustClose = TRUE;
        } else {
            $mustClose = FALSE;
        }

        if ($mustClose) {
            $keepAliveHeader = NULL;
        } else {
            $reqsRemaining = $this->maxRequests - $client->requestCount;
            $keepAliveHeader = "timeout={$this->keepAliveTimeout}, max={$reqsRemaining}";
        }

        // --- Prepare and return custom writers without normalization -----------------------------

        if ($response instanceof ResponseWriterCustom) {
            $subject = new ResponseWriterSubject;
            $subject->socket = $client->socket;
            $subject->writeWatcher = $client->writeWatcher;
            $subject->mustClose = $mustClose;
            $subject->dateHeader = $this->httpDateNow;
            $subject->serverHeader = $this->sendServerToken ? self::NAME : NULL;
            $subject->keepAliveHeader = $keepAliveHeader;
            $subject->defaultContentType = $this->defaultContentType;
            $subject->defaultTextCharset = $this->defaultTextCharset;
            $subject->autoReasonPhrase = $this->autoReasonPhrase;
            $subject->debug = $this->debug;

            return $this->prepareResponseWriter($response, $subject, $cycle);
        }

        // --- If we're still here let's start normalizing things ----------------------------------

        $proto = $request['SERVER_PROTOCOL'];

        list($status, $reason, $body) = $response->toList();

        $is1xxResponse = ($status < 200);
        if (!($is1xxResponse || $mustClose) && $response->hasHeaderMatch('Connection', 'close')) {
            // If the Response explicitly specifies a close then honor that assignment.
            $mustClose = TRUE;
        }

        // --- Handle 1xx responses ----------------------------------------------------------------

        if ($is1xxResponse && $body == '') {
            $socket = $client->socket;
            $watcher = $client->writeWatcher;
            $finalReason = $reason != '' ? " {$reason}" : '';
            $finalHeaders = $response->getRawHeaders();
            $response = "HTTP/{$proto} {$status}{$finalReason}{$finalHeaders}\r\n\r\n";
            $mustClose = FALSE;

            return new StringWriter($this->reactor, $socket, $watcher, $response, $mustClose);

        } elseif ($is1xxResponse) {
            $this->assignExceptionResponse($cycle, new \DomainException(
                '1xx response must not contain an entity body'
            ));

            return $this->makeResponseWriter($cycle);
        }

        // --- Normalize Content-Length and Transfer-Encoding --------------------------------------

        if (empty($body) || is_scalar($body)) {
            $isStringBody = TRUE;
            $transferEncoding = 'identity';
            $response->setHeader('Content-Length', strlen($body));
        } else {
            $isStringBody = FALSE;
            $transferEncoding = ($proto < 1.1) ? 'identity' : 'chunked';
            $response->removeHeader('Content-Length');
            $mustClose = $mustClose ?: ($proto < 1.1);
        }

        $response->setHeader('Transfer-Encoding', $transferEncoding);

        // --- Normalize Connection and Keep-Alive -------------------------------------------------

        if ($mustClose) {
            $response->setHeader('Connection', 'close');
            $response->removeHeader('Keep-Alive');
        } elseif ($keepAliveHeader) {
            $response->addHeader('Connection', 'keep-alive');
            $response->setHeader('Keep-Alive', $keepAliveHeader);
        } else {
            $response->addHeader('Connection', 'keep-alive');
            $response->removeHeader('Keep-Alive');
        }

        // --- Normalize Content-Type --------------------------------------------------------------

        $contentType = $response->getHeaderSafe('Content-Type') ?: $this->defaultContentType;
        if (stripos($contentType, 'text/') === 0 && stripos($contentType, 'charset=') === FALSE) {
            $contentType .= "; charset={$this->defaultTextCharset}";
        }

        $response->setHeader('Content-Type', $contentType);

        // --- Normalize other miscellaneous stuff -------------------------------------------------

        $response->setHeader('Date', $this->httpDateNow);
        if ($this->sendServerToken) {
            $response->setHeader('Server', self::NAME);
        }

        if ($this->autoReasonPhrase && $reason == '') {
            $reasonConstant = "Aerys\Reason::HTTP_{$status}";
            $reason = defined($reasonConstant) ? constant($reasonConstant) : '';
            $response->setReason($reason);
        }

        // IMPORTANT: This MUST happen AFTER entity header normalization or headers
        // won't be correct when responding to HEAD requests. Don't move this above
        // the header normalization lines!
        if ($request['REQUEST_METHOD'] === 'HEAD') {
            $body = '';
            $response->setBody($body);
            $isStringBody = TRUE;
        }

        // --- Build the final Writer --------------------------------------------------------------

        $finalReason = $reason != '' ? " {$reason}" : '';
        $finalHeaders = $response->getRawHeaders();
        $socket = $client->socket;
        $watcher = $client->writeWatcher;
        $reactor = $this->reactor;

        if ($isStringBody) {
            $response = "HTTP/{$proto} {$status}{$finalReason}{$finalHeaders}\r\n\r\n{$body}";
            return new StringWriter($reactor, $socket, $watcher, $response, $mustClose);
        } elseif ($mustClose) {
            $headers = "HTTP/{$proto} {$status}{$finalReason}{$finalHeaders}\r\n\r\n";
            return new GeneratorWriter($reactor, $socket, $watcher, $headers, $body , $mustClose);
        } else {
            $headers = "HTTP/{$proto} {$status}{$finalReason}{$finalHeaders}\r\n\r\n";
            return new GeneratorWriterChunked($reactor, $socket, $watcher, $headers, $body , $mustClose);
        }
    }

    private function prepareResponseWriter(ResponseWriterCustom $writer, ResponseWriterSubject $subject, Cycle $cycle) {
        try {
            $writer->prepareResponse($subject);
            return $writer;
        } catch (\Exception $e) {
            $this->assignExceptionResponse($cycle, new \DomainException(
                '1xx response must not contain an entity body'
            ));
            return $this->makeResponseWriter($cycle);
        }
    }

    private function onFutureWriteResolution($client, $future) {
        $client->pendingWriter = NULL;

        if ($future->succeeded()) {
            $shouldClose = $future->getValue();
            return $this->afterResponseWrite($client, $shouldClose);
        }

        $e = $future->getError();

        if (!$e instanceof TargetPipeException) {
            // @TODO Log $e

            // Write failure occurred for some reason other than a premature client
            // disconnect. This represents an error in our program and requires logging.
        }

        // Always close the connection if an error occurred while writing
        $this->closeClient($client);
    }

    private function afterResponseWrite($client, $shouldClose) {
        if ($client->isGone) {
            return; // Nothing to do; the client socket has already disconnected/exported
        }

        $requestId = key($client->cycles);
        $cycle = $client->cycles[$requestId];

        if ($shouldClose) {
            $this->closeClient($client);
        } else {
            $this->shiftClientPipeline($client, $requestId);
        }
    }

    /**
     * Assume control of the specified socket
     *
     * Note that exported sockets continue to count against the server's maxConnections limit to
     * protect against DoS. When an application is finished with the exported socket it MUST
     * invoke the callback returned at index 1 of this function's return array.
     *
     * @param int $socketId
     * @throws DomainException on unknown socket ID
     * @return array[int $socketId, Closure $closeCallback]
     */
    public function exportSocket($socketId) {
        if (isset($this->clients[$socketId])) {
            $client = $this->clients[$socketId];
            $socket = $client->socket;
            $this->exportedSocketIdMap[$socketId] = $socket;

            // Don't decrement the client count because we want to keep track of
            // resource usage as long as the exported client is still open.
            $this->clearClientReferences($client, $decrementClientCount = FALSE);
            $closeCallback = function() use ($socketId) { $this->closeExportedSocket($socketId); };

            return [$socket, $closeCallback];
        } else {
            throw new \DomainException(
                sprintf("Unknown socket ID: %s", $socketId)
            );
        }
    }

    private function closeExportedSocket($socketId) {
        if (isset($this->exportedSocketIdMap[$socketId])) {
            $socket = $this->exportedSocketIdMap[$socketId];
            $this->cachedClientCount--;
            $this->doSocketClose($socket);
            unset($this->exportedSocketIdMap[$socketId]);
        }
    }

    private function clearClientReferences($client, $decrementClientCount) {
        $this->reactor->cancel($client->readWatcher);
        $this->reactor->cancel($client->writeWatcher);

        if ($client->cycles) {
            // @TODO Account for in-progress future responses?
        }

        // We might have pending async jobs out for this client. Set a flag so async
        // jobs holding a reference to the client will know to ignore future values
        // when they are eventually resolved.
        $client->isGone = TRUE;

        $client->parser = NULL;
        $client->cycles = NULL;

        unset(
            $this->clients[$client->id],
            $this->keepAliveTimeouts[$client->id]
        );

        $this->cachedClientCount -= $decrementClientCount;

        if ($this->state === self::PAUSED
            && $this->maxConnections > 0
            && $this->cachedClientCount <= $this->maxConnections
        ) {
            $this->resumeClientAcceptance();
        }
    }

    private function closeClient($client) {
        $this->clearClientReferences($client, $decrementClientCount = TRUE);
        $this->doSocketClose($client->socket);

        // If we're shutting down and no more clients remain we can fulfill our stop promise
        if ($this->state === self::STOPPING && empty($this->clients)) {
            $this->stopPromise->succeed();
        }
    }

    private function doSocketClose($socket) {
        $socketId = (int) $socket;

        if (isset(stream_context_get_options($socket)['ssl'])) {
            @stream_socket_enable_crypto($socket, FALSE);
        }

        if ($this->socketSoLingerZero) {
            $this->closeSocketWithSoLingerZero($socket);
        } elseif (is_resource($socket)) {
            stream_socket_shutdown($socket, STREAM_SHUT_WR);
            @fread($socket, $this->readGranularity);
            @fclose($socket);
        }
    }

    private function closeSocketWithSoLingerZero($socket) {
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
            $client->cycles[$requestId]
        );

        // Disable active onWritable stream watchers if the pipeline is no longer write-ready
        if (!current($client->pipeline)) {
            $this->reactor->disable($client->writeWatcher);
            $this->renewKeepAliveTimeout($client->id);
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
     * @param int $option A server option constant
     * @param mixed $value The option value to assign
     * @throws \DomainException On unrecognized option key
     * @return void
     */
    public function setOption($option, $value) {
        switch ($option) {
            case self::OP_MAX_CONNECTIONS:
                $this->setMaxConnections($value); break;
            case self::OP_MAX_REQUESTS:
                $this->setMaxRequests($value); break;
            case self::OP_KEEP_ALIVE_TIMEOUT:
                $this->setKeepAliveTimeout($value); break;
            case self::OP_DISABLE_KEEP_ALIVE:
                $this->setDisableKeepAlive($value); break;
            case self::OP_MAX_HEADER_BYTES:
                $this->setMaxHeaderBytes($value); break;
            case self::OP_MAX_BODY_BYTES:
                $this->setMaxBodyBytes($value); break;
            case self::OP_DEFAULT_CONTENT_TYPE:
                $this->setDefaultContentType($value); break;
            case self::OP_DEFAULT_TEXT_CHARSET:
                $this->setDefaultTextCharset($value); break;
            case self::OP_AUTO_REASON_PHRASE:
                $this->setAutoReasonPhrase($value); break;
            case self::OP_SEND_SERVER_TOKEN:
                $this->setSendServerToken($value); break;
            case self::OP_NORMALIZE_METHOD_CASE:
                $this->setNormalizeMethodCase($value); break;
            case self::OP_REQUIRE_BODY_LENGTH:
                $this->setRequireBodyLength($value); break;
            case self::OP_SOCKET_SO_LINGER_ZERO:
                $this->setSocketSoLingerZero($value); break;
            case self::OP_SOCKET_BACKLOG_SIZE:
                $this->setSocketBacklogSize($value); break;
            case self::OP_ALLOWED_METHODS:
                $this->setAllowedMethods($value); break;
            case self::OP_DEFAULT_HOST:
                $this->setDefaultHost($value); break;
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
        if (is_string($methods)) {
            $methods = array_map('trim', explode(',', $methods));
        }

        if (!($methods && is_array($methods))) {
            throw new \DomainException(
                'Allowed method assignment requires a comma delimited string or an array of HTTP methods'
            );
        }

        $methods = array_unique($methods);

        if (!in_array('GET', $methods)) {
            throw new ConfigException(
                'Cannot disallow GET method'
            );
        }

        if (!in_array('HEAD', $methods)) {
            throw new ConfigException(
                'Cannot disallow HEAD method'
            );
        }

        // @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec2.html#sec2.2
        // @TODO Validate characters in method names match the RFC 2616 ABNF token definition:
        // token          = 1*<any CHAR except CTLs or separators>

        $methods = array_filter($methods, function($m) { return $m && is_string($m); });

        $this->allowedMethods = $methods;
    }

    private function setDefaultHost($hostId) {
        $this->defaultHost = $hostId;
    }

    /**
     * Retrieve a server option value
     *
     * @param int $option A server option constant
     * @throws \DomainException On unknown option
     * @return mixed The current value of the requested option
     */
    public function getOption($option) {
        switch ($option) {
            case self::OP_MAX_CONNECTIONS:
                return $this->maxConnections;
            case self::OP_MAX_REQUESTS:
                return $this->maxRequests;
            case self::OP_KEEP_ALIVE_TIMEOUT:
                return $this->keepAliveTimeout;
            case self::OP_DISABLE_KEEP_ALIVE:
                return $this->disableKeepAlive;
            case self::OP_MAX_HEADER_BYTES:
                return $this->maxHeaderBytes;
            case self::OP_MAX_BODY_BYTES:
                return $this->maxBodyBytes;
            case self::OP_DEFAULT_CONTENT_TYPE:
                return $this->defaultContentType;
            case self::OP_DEFAULT_TEXT_CHARSET:
                return $this->defaultTextCharset;
            case self::OP_AUTO_REASON_PHRASE:
                return $this->autoReasonPhrase;
            case self::OP_SEND_SERVER_TOKEN:
                return $this->sendServerToken;
            case self::OP_NORMALIZE_METHOD_CASE:
                return $this->normalizeMethodCase;
            case self::OP_REQUIRE_BODY_LENGTH:
                return $this->requireBodyLength;
            case self::OP_SOCKET_SO_LINGER_ZERO:
                return $this->socketSoLingerZero;
            case self::OP_SOCKET_BACKLOG_SIZE:
                return $this->hostBinder->getSocketBacklogSize();
            case self::OP_ALLOWED_METHODS:
                return array_keys($this->allowedMethods);
            case self::OP_DEFAULT_HOST:
                return $this->defaultHost;
            default:
                throw new \DomainException(
                    "Unknown server option: {$option}"
                );
        }
    }

    /**
     * Retrieve an indexed array mapping available options to their current values
     *
     * @return array
     */
    public function getAllOptions() {
        return [
            self::OP_MAX_CONNECTIONS        => $this->maxConnections,
            self::OP_MAX_REQUESTS           => $this->maxRequests,
            self::OP_KEEP_ALIVE_TIMEOUT     => $this->keepAliveTimeout,
            self::OP_DISABLE_KEEP_ALIVE     => $this->disableKeepAlive,
            self::OP_MAX_HEADER_BYTES       => $this->maxHeaderBytes,
            self::OP_MAX_BODY_BYTES         => $this->maxBodyBytes,
            self::OP_DEFAULT_CONTENT_TYPE   => $this->defaultContentType,
            self::OP_DEFAULT_TEXT_CHARSET   => $this->defaultTextCharset,
            self::OP_AUTO_REASON_PHRASE     => $this->autoReasonPhrase,
            self::OP_SEND_SERVER_TOKEN      => $this->sendServerToken,
            self::OP_NORMALIZE_METHOD_CASE  => $this->normalizeMethodCase,
            self::OP_REQUIRE_BODY_LENGTH    => $this->requireBodyLength,
            self::OP_SOCKET_SO_LINGER_ZERO  => $this->socketSoLingerZero,
            self::OP_SOCKET_BACKLOG_SIZE    => $this->hostBinder->getSocketBacklogSize(),
            self::OP_ALLOWED_METHODS        => array_keys($this->allowedMethods),
            self::OP_DEFAULT_HOST           => $this->defaultHost
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
    }
}
