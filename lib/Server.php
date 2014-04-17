<?php

namespace Aerys;

use Alert\Reactor,
    Alert\Promise,
    Alert\Future,
    Alert\Success,
    Alert\Aggregate;

class Server {
    const NAME = 'Aerys/0.1.0-devel';
    const VERSION = '0.1.0-dev';

    const STOPPED = 0;
    const STARTING = 1;
    const STARTED = 2;
    const PAUSED = 3;
    const STOPPING = 4;

    private static $BODY_STRING = 1;
    private static $BODY_CUSTOM = 2;
    private static $BODY_GENERATOR = 4;
    private static $BODY_ITERATOR = 8;

    private $state = self::STOPPED;
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
    private $lastRequestId = PHP_INT_MAX * -1;

    private $now;
    private $httpDateNow;
    private $httpDateFormat = 'D, d M Y H:i:s';
    private $keepAliveWatcher;
    private $keepAliveTimeouts = [];

    private $errorLogPath = 'php://stderr';
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
    private $isExtSocketsEnabled;

    public function __construct(Reactor $reactor, HostBinder $hb = NULL, $debug = FALSE) {
        $this->reactor = $reactor;
        $this->hostBinder = $hb ?: new HostBinder;
        $this->debug = (bool) $debug;
        $this->observers = new \SplObjectStorage;
        $this->isExtSocketsEnabled = extension_loaded('sockets');
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
     * @return \Alert\Future Returns a Future that will resolve once all startup tasks complete
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
        $startFuture->onComplete(function($future) { $this->onStartCompletion($future); });

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
            }, $intervalInSeconds = 1);

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
     * @return \Alert\Future Returns a Future that will resolve once the shutdown routine completes.
     *                       If the server is already started no action occurs and NULL is returned.
     */
    public function stop() {
        if ($this->state === self::STOPPING || $this->state === self::STOPPED) {
            throw new \LogicException(
                'Server already stopping'
            );
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

        $stopFuture->onComplete(function(Future $f) { $this->onStopCompletion($f); });

        return $stopFuture;
    }

    private function stopClient($client, Response $response) {
        if ($client->cycles) {
            $unassignedRequestIds = array_keys(array_diff_key($client->cycles, $client->pipeline));
            foreach ($unassignedRequestIds as $requestId) {
                $cycle = $client->cycles[$requestId];
                $cycle->response = $response;
                $this->hydrateResponderPipeline($cycle);
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

        $onWritable = function() use ($client) { $client->pendingResponder->writeResponse(); };
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
        $this->hydrateResponderPipeline($cycle);
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
            $this->hydrateResponderPipeline($cycle);
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
        $__ucHeaders = array_change_key_case($__headers, CASE_UPPER);

        $cycle = new Cycle;
        $cycle->requestId = ++$this->lastRequestId;
        $cycle->client = $client;
        $cycle->protocol = $__protocol;
        $cycle->method = $__method;
        $cycle->body = $__body;
        $cycle->headers = $__headers;
        $cycle->ucHeaders = $__ucHeaders;
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

        if (empty($__ucHeaders['EXPECT'])) {
            $cycle->expectsContinue = FALSE;
        } elseif (stristr($__ucHeaders['EXPECT'][0], '100-continue')) {
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

        $request = [
            'AERYS_SOCKET_ID'   => $client->id,
            'AERYS_REQUEST_ID'  => $cycle->requestId,
            'ASGI_VERSION'      => '0.1',
            'ASGI_NON_BLOCKING' => TRUE,
            'ASGI_ERROR'        => NULL,
            'ASGI_INPUT'        => $cycle->body,
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

        if (!empty($__ucHeaders['CONTENT-TYPE'])) {
            $request['CONTENT_TYPE'] = $__ucHeaders['CONTENT-TYPE'][0];
            unset($__ucHeaders['CONTENT-TYPE']);
        }

        if (!empty($__ucHeaders['CONTENT-LENGTH'])) {
            $request['CONTENT_LENGTH'] = $__ucHeaders['CONTENT-LENGTH'][0];
            unset($__ucHeaders['CONTENT-LENGTH']);
        }

        $request['QUERY'] = $cycle->uriQuery ? parse_str($cycle->uriQuery, $request['QUERY']) : [];

        // @TODO Add cookie parsing
        //if (!empty($ucHeaders['COOKIE']) && ($cookies = $this->parseCookies($ucHeaders['COOKIE']))) {
        //    $request['COOKIE'] = $cookies;
        //}

        // @TODO Add multipart entity parsing

        foreach ($__ucHeaders as $field => $value) {
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
        } elseif (!in_array($__method, $this->allowedMethods)) {
            $cycle->response = (new Response)
                ->setStatus(Status::METHOD_NOT_ALLOWED)
                ->setHeader('Allow', implode(',', $this->allowedMethods))
                ->setHeader('Connection', 'close')
            ;
        } elseif ($__method === 'TRACE' && empty($cycle->ucHeaders['MAX_FORWARDS'])) {
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
                ->setHeader('Allow', implode(',', $this->allowedMethods))
            ;
        } elseif ($this->requireBodyLength && $__headersOnly && empty($cycle->ucHeaders['CONTENT-LENGTH'])) {
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
            $this->hydrateResponderPipeline($cycle);
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
                $cycle->request[$key] = $value;
            }
        }
    }

    private function invokeHostApplication($cycle) {
        try {
            $responder = $cycle->host->getApplication();
            $response = $responder($cycle->request);
            $this->assignResponse($cycle, $response);
        } catch (\Exception $exception) {
            $this->assignExceptionResponse($cycle, $exception);
        }
    }

    private function assignResponse($cycle, $response) {
        if (is_string($response)) {
            $cycle->response = (new Response)->setBody($response);
            $this->hydrateResponderPipeline($cycle);
        } elseif ($response instanceof Response) {
            $cycle->response = $response;
            $this->hydrateResponderPipeline($cycle);
        } elseif ($response instanceof \Generator) {
            $this->advanceResponseGenerator($cycle, $response);
        } elseif ($response instanceof Future) {
            $response->onComplete(function($future) use ($cycle) {
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
                $yielded->onComplete(function($future) use ($cycle, $generator) {
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
                $multiFuture->onComplete(function($future) use ($cycle, $generator) {
                    $this->onFutureYieldResolution($cycle, $generator, $future);
                });
            } elseif ($yielded instanceof Response) {
                $cycle->response = $yielded;
                $this->hydrateResponderPipeline($cycle);
            } elseif (is_string($yielded)) {
                // If the generator yields a string we assume that all remaining values
                // yielded from the generator will be strings or resolvable futures.
                // A streaming response is assumed and the generator is its body.
                $cycle->response = (new Response)->setBody($generator);
                $this->hydrateResponderPipeline($cycle);
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
        // @TODO Log $e to whatever error logging facility we can access

        $displayMsg = $this->debug
            ? "<pre>{$exception}</pre>"
            : '<p>Something went terribly wrong</p>';

        $status = Status::INTERNAL_SERVER_ERROR;
        $reason = Reason::HTTP_500;
        $body = "<html><body><h1>{$status} {$reason}</h1><p>{$displayMsg}</p></body></html>";
        $cycle->response = (new Response)->setStatus($status)->setReason($reason)->setBody($body);

        $this->hydrateResponderPipeline($cycle);
    }

    private function hydrateResponderPipeline($cycle) {
        // @TODO Retrieve and execute Host::getAfterResponder() here ...

        $client = $cycle->client;

        foreach ($client->cycles as $requestId => $cycle) {
            if (isset($client->pipeline[$requestId])) {
                continue;
            } elseif ($cycle->response) {
                $client->pipeline[$requestId] = $this->generateResponder($cycle);
            } else {
                break;
            }
        }

        // IMPORTANT: don't break the pipeline order!
        reset($client->cycles);

        // If we already have a pending responder in progress we have to wait until
        // it's complete before starting the next one. Otherwise we need to ensure
        // the first request in the pipeline has a responder assigned before proceeding.
        if (!$client->pendingResponder && ($responder = current($client->pipeline))) {
            $this->initiateResponderWrite($client, $responder);
        }
    }

    private function generateResponder($cycle) {
        $request = $cycle->request;
        $response = $cycle->response;

        $proto = $request['SERVER_PROTOCOL'];
        $status = $response->getStatus();
        $body = $response->getBody();

        if ($status >= 200) {
            $mustClose = $this->mustCloseAfterResponse($cycle);
            $bodyType = $this->determineResponseBodyType($body);
            $bodyLen = $this->normalizeContentLengthHeader($response, $proto, $body, $bodyType);
            $this->normalizeConnectionHeader($cycle);
            $this->normalizeContentTypeHeader($response);
        } elseif (!$response->hasBody()) {
            // Never close after a 1xx response
            $mustClose = FALSE;
            $bodyType = self::$BODY_STRING;
            $bodyLen = 0;
        } else {
            $this->assignExceptionResponse($cycle, new \DomainException(
                '1xx response cannot contain an entity body'
            ));

            return $this->generateResponder($cycle);
        }

        $response->setHeader('Date', $this->httpDateNow);

        if ($this->sendServerToken) {
            $response->setHeader('Server', self::NAME);
        }

        $reason = $response->getReason();
        if ($this->autoReasonPhrase && !($reason || $reason === '0')) {
            $reasonConstant = "Aerys\\Reason::HTTP_{$status}";
            $reason = defined($reasonConstant) ? constant($reasonConstant) : '';
            $response->setReason($reason);
        }

        if ($status >= 200 && $mustClose || ($proto < 1.1 && $bodyLen === -1)) {
            $response->setHeader('Connection', 'close');
            $response->removeHeader('Keep-Alive');
            $cycle->closeAfterResponse = TRUE;
        } else {
            $cycle->closeAfterResponse = FALSE;
        }

        $body = $response->getBody();

        // This MUST happen AFTER entity header normalization or headers won't be
        // correct when responding to HEAD requests. Don't move this above the header
        // generation/normalization lines!
        if ($request['REQUEST_METHOD'] === 'HEAD') {
            $response->setBody($body = '');
            $bodyType = self::$BODY_STRING;
        }

        $reason = $reason != '' ? " {$reason}" : '';
        $headers = $response->getRawHeaders();
        $statusLineAndHeaders = "HTTP/{$proto} {$status}{$reason}{$headers}\r\n\r\n";

        $pr = new Write\PendingResponse;
        $pr->headers = $statusLineAndHeaders;
        $pr->body = $body;
        $pr->writeWatcher = $cycle->client->writeWatcher;
        $pr->destination = $cycle->client->socket;

        switch ($bodyType) {
            case self::$BODY_STRING:
                return new Write\StringWriter($this->reactor, $pr);
            case self::$BODY_CUSTOM:
                return $body->getResponder($this->reactor, $pr);
            case self::$BODY_GENERATOR:
                return $cycle->closeAfterResponse
                    ? new Write\GeneratorWriter($this->reactor, $pr)
                    : new Write\ChunkedGeneratorWriter($this->reactor, $pr);
            case self::$BODY_ITERATOR:
                return $cycle->closeAfterResponse
                    ? new Write\IteratorWriter($this->reactor, $pr)
                    : new Write\ChunkedIteratorWriter($this->reactor, $pr);
            default:
                throw new \UnexpectedValueException(
                    sprintf('Unexpected responder body type: %s', $bodyType)
                );
        }
    }

    private function determineResponseBodyType($body) {
        if (empty($body) || is_scalar($body)) {
            return self::$BODY_STRING;
        } elseif ($body instanceof \Generator) {
            return self::$BODY_GENERATOR;
        } elseif ($body instanceof \Iterator) {
            return self::$BODY_ITERATOR;
        } else {
            return self::$BODY_CUSTOM;
        }
    }

    private function mustCloseAfterResponse($cycle) {
        // If keep-alive is disabled or the server is stopping we always close.
        if ($this->disableKeepAlive || $this->state === self::STOPPING) {
            return TRUE;
        }

        // If the client has exceeded the max allowable requests per connection
        // we always close.
        if ($this->maxRequests > 0 && $cycle->client->requestCount >= $this->maxRequests) {
            return TRUE;
        }

        $request = $cycle->request;
        $response = $cycle->response;

        if ($request['SERVER_PROTOCOL'] < 1.1) {
            return TRUE;
        }

        // If the request indicated a close preference we agree to that. If the request uses
        // HTTP/1.0 we may still have to close if the response content length is unknown.
        if (isset($request['HTTP_CONNECTION'])) {
            return (stripos($request['HTTP_CONNECTION'], 'close') !== FALSE);
        }

        // If response mandates a close preferences then use that.
        return $response->hasHeader('Connection')
            ? (stripos($response->getHeaderMerged('Connection'), 'close') !== FALSE)
            : FALSE;
    }

    private function normalizeConnectionHeader($cycle) {
        $response = $cycle->response;
        $shouldClose = $cycle->closeAfterResponse;

        if ($shouldClose) {
            $response->setHeader('Connection', 'close');
            return;
        }

        $value = $shouldClose ? 'close' : 'keep-alive';
        $response->addHeader('Connection', $value);

        if (empty($shouldClose) && $this->keepAliveTimeout > 0) {
            $timeout = $this->keepAliveTimeout;
            $remaining = $this->maxRequests - $cycle->client->requestCount;
            $response->setHeader('Keep-Alive', "timeout={$timeout}, max={$remaining}");
        }
    }

    private function normalizeContentLengthHeader($response, $proto, $body, $bodyType) {
        switch ($bodyType) {
            case self::$BODY_STRING:
                $bodyLen = strlen($body);
                $response->setHeader('Content-Length', $bodyLen);
                $response->removeHeader('Transfer-Encoding');
                break;
            case self::$BODY_ITERATOR:
                // fallthrough
            case self::$BODY_GENERATOR:
                $bodyLen = -1;
                $response->removeHeader('Content-Length');
                if ($proto > 1.0) {
                    $response->setHeader('Transfer-Encoding', 'chunked');
                }
                break;
            case self::$BODY_CUSTOM:
                $bodyLen = $body->getContentLength();
                if ($bodyLen >= 0) {
                    $response->setHeader('Content-Length', $bodyLen);
                    $response->removeHeader('Transfer-Encoding');
                } else {
                    $response->removeHeader('Content-Length');
                }
                break;
            default:
                throw new \Exception(
                    'Custom body types not yet implemented'
                );
        }

        return $bodyLen;
    }

    private function normalizeContentTypeHeader($response) {
        $contentType = $response->hasHeader('Content-Type')
            ? $this->normalizeContentTypeTextCharset($response)
            : "{$this->defaultContentType}; charset={$this->defaultTextCharset}";

        $response->setHeader('Content-Type', $contentType);
    }

    private function normalizeContentTypeTextCharset($response) {
        $contentType = $response->getHeader('Content-Type');
        if (stripos($contentType, "text/") === 0 && stripos($contentType, 'charset=') === FALSE) {
            $contentType .= "; charset={$this->defaultTextCharset}";
        }

        return $contentType;
    }

    private function initiateResponderWrite($client, ResponseWriter $responder) {
        $writeResult = $responder->writeResponse();

        if ($writeResult === ResponseWriter::COMPLETED) {
            $this->afterResponseWrite($client);
        } elseif ($writeResult === ResponseWriter::FAILED) {
            $this->closeClient($client);
        } elseif ($writeResult instanceof Future) {
            // IMPORTANT: Client writability watchers map to the client's pendingResponder
            // property. By always storing the currently in-progress responder in this property
            // we're able to reuse the same writability watcher for the full life of the
            // client connection. This is a big performance win and any refactoring should
            // retain this single watcher approach.
            $client->pendingResponder = $responder;

            // The write result is a future value; assign the appropriate
            // callbacks to invoke when the future eventually resolves.
            $writeResult->onComplete(function($future) use ($client) {
                $this->onFutureResponseWriteComplete($client, $future);
            });
        } else {
            // @TODO this represents a clear program error. However, it may be
            // preferable to send an error response instead of killing the server
            // with a fatal uncaught exception.
            throw new \UnexpectedValueException(
                'Unexpected response writer code'
            );
        }
    }

    private function onFutureResponseWriteComplete($client, $future) {
        $client->pendingResponder = NULL;

        if ($future->succeeded()) {
            return $this->afterResponseWrite($client);
        }

        $e = $future->getError();

        if (!$e instanceof TargetPipeException) {
            // @TODO Log $e

            // Write failure occurred for some reason other than a premature client
            // disconnect. This represents an error in our program and requires logging.
        }

        // Always close the connection after an error
        $this->closeClient($client);
    }

    private function afterResponseWrite($client) {
        $requestId = key($client->cycles);
        $cycle = $client->cycles[$requestId];

        if ($cycle->response->hasExportCallback()) {
            $this->upgradeSocket($cycle);
        } elseif ($cycle->closeAfterResponse) {
            $this->closeClient($client);
        } else {
            $this->shiftClientPipeline($client, $requestId);
        }
    }

    private function upgradeSocket($cycle) {
        try {
            $client = $cycle->client;
            $socket = $client->socket;
            $socketId = (int) $socket;
            $this->exportedSocketIdMap[$socketId] = $socket;

            $upgradeCallback = $cycle->response->getExportCallback();

            // Don't decrement the client count because we want to keep track of
            // resource usage as long as the exported client is still open.
            $this->clearClientReferences($client, $decrementClientCount = FALSE);

            $upgradeCallback($socket, $cycle->request, $onClose = function() use ($socketId) {
                $this->closeExportedSocket($socketId);
            });
        } catch (\Exception $e) {
            $this->closeExportedSocket($socketId);
            // @TODO Log $e here
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
            case 'errorlogpath':
                $this->setErrorLogPath($value); break;
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

    private function setErrorLogPath($path) {
        if ($this->state !== self::$STOPPED) {
            //$this->errorLogger->log('Cannot modify error log path while server is running');
            // @TODO Update for logging
        } elseif ($path && is_string($path)) {
            $this->errorLogPath = $path;
        } else {
            throw new \InvalidArgumentException(
                'Error log path expects a non-empty string'
            );
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

        $this->allowedMethods = array_unique($methods);
    }

    private function setDefaultHost($hostId) {
        $this->defaultHost = $hostId;
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
                return $this->maxConnections;
            case 'maxrequests':
                return $this->maxRequests;
            case 'keepalivetimeout':
                return $this->keepAliveTimeout;
            case 'disablekeepalive':
                return $this->disableKeepAlive;
            case 'maxheaderbytes':
                return $this->maxHeaderBytes;
            case 'maxbodybytes':
                return $this->maxBodyBytes;
            case 'defaultcontenttype':
                return $this->defaultContentType;
            case 'defaulttextcharset':
                return $this->defaultTextCharset;
            case 'autoreasonphrase':
                return $this->autoReasonPhrase;
            case 'errorstream':
                return $this->errorStream;
            case 'sendservertoken':
                return $this->sendServerToken;
            case 'normalizemethodcase':
                return $this->normalizeMethodCase;
            case 'requirebodylength':
                return $this->requireBodyLength;
            case 'socketsolingerzero':
                return $this->socketSoLingerZero;
            case 'socketbacklogsize':
                return $this->hostBinder->getSocketBacklogSize();
            case 'allowedmethods':
                return $this->allowedMethods;
            case 'defaulthost':
                return $this->defaultHost;
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
            'errorLogPath'          => $this->errorLogPath,
            'sendServerToken'       => $this->sendServerToken,
            'normalizeMethodCase'   => $this->normalizeMethodCase,
            'requireBodyLength'     => $this->requireBodyLength,
            'socketSoLingerZero'    => $this->socketSoLingerZero,
            'socketBacklogSize'     => $this->hostBinder->getSocketBacklogSize(),
            'allowedMethods'        => $this->allowedMethods,
            'defaultHost'           => $this->defaultHost
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
