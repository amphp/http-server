<?php

namespace Aerys;

use Amp\Reactor;
use Amp\Future;
use Amp\Success;
use Amp\Promise;
use Amp\Resolver;
use Amp\Combinator;

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
    private $combinator;
    private $debug;
    private $stopFuture;
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
    private $keepAliveTimeout = 10;
    private $defaultHost;
    private $defaultContentType = 'text/html';
    private $defaultTextCharset = 'utf-8';
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

    public function __construct(
        Reactor $reactor,
        HostBinder $hb = null,
        Combinator $combinator = null,
        Resolver $resolver = null,
        $debug = false
    ) {
        $this->reactor = $reactor;
        $this->hostBinder = $hb ?: new HostBinder;
        $this->combinator = $combinator ?: new Combinator($reactor);
        $this->resolver = $resolver ?: new Resolver($reactor);
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

        return $futures ? $this->combinator->all($futures) : new Success;
    }

    /**
     * Start the server
     *
     * IMPORTANT: The server's event reactor must still be started externally.
     *
     * @param mixed $hosts A HostDefinition, HostGroup or array of HostDefinition instances
     * @param array $listeningSockets Optional array mapping bind addresses to existing sockets
     * @throws \LogicException If no hosts have been added to the specified collection
     * @throws \RuntimeException On socket bind failure
     * @return \Amp\Promise Returns a Promise that resolves once all startup tasks complete
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
        $acceptor = function($r, $w, $s) { $this->accept($s); };
        $tlsAcceptor = function($r, $w, $s) { $this->acceptTls($s); };

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
        $startPromise = $this->notifyObservers(self::STARTING);
        $startPromise->when(function($e, $r) { $this->onStartCompletion($e, $r); });

        return $startPromise;
    }

    private function normalizeStartHosts($hostOrCollection) {
        if ($hostOrCollection instanceof HostGroup) {
            $hosts = $hostOrCollection;
        } elseif ($hostOrCollection instanceof HostDefinition) {
            $hosts = new HostGroup;
            $hosts->addHost($hostOrCollection);
        } elseif (is_array($hostOrCollection)) {
            $hosts = new HostGroup;
            foreach ($hostOrCollection as $host) {
                $hosts->addHost($host);
            }
        }

        return $hosts;
    }

    private function onStartCompletion(\Exception $e = null, $r = null) {
        if (empty($e)) {
            foreach ($this->acceptWatchers as $acceptWatcher) {
                $this->reactor->enable($acceptWatcher);
            }
            $this->renewHttpDate();
            $this->keepAliveWatcher = $this->reactor->repeat(function() {
                $this->timeoutKeepAlives();
            }, $msInterval = 1000);

            $this->state = self::STARTED;
        } else {
            // @TODO Log $e;
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
     * @return \Amp\Promise Returns a Promise that resolves when shutdown completes.
     */
    public function stop() {
        if ($this->state === self::STOPPING) {
            return $this->stopFuture->promise();
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
            $this->stopFuture = new Future($this->reactor);
            $response = [
                'status' => Status::SERVICE_UNAVAILABLE,
                'header' => ['Connection: close'],
                'body'   => '<html><body><h1>503 Service Unavailable</h1></body></html>'
            ];

            foreach ($this->clients as $client) {
                $this->stopClient($client, $response);
            }

            $stopFuture = $this->combinator->all([$this->stopFuture->promise(), $observerFuture]);
        }

        $stopFuture->when(function($e, $r) { $this->onStopCompletion($e, $r); });

        return $stopFuture;
    }

    private function stopClient($client, $response) {
        if ($client->cycles) {
            $unassignedRequestIds = array_keys(array_diff_key($client->cycles, $client->pipeline));
            foreach ($unassignedRequestIds as $requestId) {
                $requestCycle = $client->cycles[$requestId];
                $requestCycle->response = $response;
                $this->hydrateResponderPipeline($requestCycle);
            }
        } else {
            $this->closeClient($client);
        }
    }

    private function onStopCompletion(\Exception $e = null, $r = null) {
        $this->stopFuture = NULL;
        $this->reactor->cancel($this->keepAliveWatcher);
        $this->acceptWatchers = [];
        $this->listeningSockets = [];
        $this->state = self::STOPPED;

        if ($e) {
            // @TODO There was an error; log $e
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

        $onWritable = function() use ($client) { $client->pendingResponder->write(); };
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
            $requestCycle = $client->partialCycle;
            $client->partialCycle = NULL;
        } else {
            $requestCycle = $this->initializeCycle($client, $e->getParsedMsgArr());
        }

        $requestCycle->response = [
            'status' => $e->getCode() ?: Status::BAD_REQUEST,
            'header' => ['Connection: close'],
            'body'   => sprintf("<html><body><p>%s</p></body></html>", $e->getMessage())
        ];

        $this->hydrateResponderPipeline($requestCycle);
    }

    /**
     * @TODO Invoke HostDefinition application partial responders here (not yet implemented). These responders
     * (if present) should be used to answer request Expect headers (or whatever people wish to do
     * before the body arrives).
     *
     * @TODO Support generator multitasking in partial responders
     */
    private function onPartialRequest($client, array $parsedRequest) {
        $requestCycle = $this->initializeCycle($client, $parsedRequest);

        // @TODO Apply Host application partial responders here (not yet implemented)

        if (!$requestCycle->response && $requestCycle->expectsContinue) {
            $requestCycle->response = [
                'status' => Status::CONTINUE_100,
                'body' => ''
            ];
        }

        // @TODO After responding to an expectation we probably need to modify the request parser's
        // state to avoid parse errors after a non-100 response. Otherwise we really have no choice
        // but to close the connection after this response.
        if ($requestCycle->response) {
            $this->hydrateResponderPipeline($requestCycle);
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

        $requestCycle = new RequestCycle;
        $requestCycle->requestId = ++$this->lastRequestId;
        $requestCycle->client = $client;
        $requestCycle->protocol = $__protocol;
        $requestCycle->method = $__method;
        $requestCycle->body = $__body;
        $requestCycle->headers = $__headers;
        $requestCycle->uri = $__uri;

        if (stripos($__uri, 'http://') === 0 || stripos($__uri, 'https://') === 0) {
            extract(parse_url($__uri, $flags = EXTR_PREFIX_ALL, $prefix = '__uri_'));
            $requestCycle->hasAbsoluteUri = TRUE;
            $requestCycle->uriHost = $__uri_host;
            $requestCycle->uriPort = $__uri_port;
            $requestCycle->uriPath = $__uri_path;
            $requestCycle->uriQuery = $__uri_query;
        } elseif ($qPos = strpos($__uri, '?')) {
            $requestCycle->uriQuery = substr($__uri, $qPos + 1);
            $requestCycle->uriPath = substr($__uri, 0, $qPos);
        } else {
            $requestCycle->uriPath = $__uri;
        }

        if (empty($__headers['EXPECT'])) {
            $requestCycle->expectsContinue = FALSE;
        } elseif (stristr($__headers['EXPECT'][0], '100-continue')) {
            $requestCycle->expectsContinue = TRUE;
        } else {
            $requestCycle->expectsContinue = FALSE;
        }

        $client->requestCount++;
        $client->cycles[$requestCycle->requestId] = $requestCycle;
        $client->partialCycle = $__headersOnly ? $requestCycle : NULL;

        list($host, $isValidHost) = $this->hosts->selectHost($requestCycle, $this->defaultHost);
        $requestCycle->host = $host;

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
            'ASGI_INPUT'        => $requestCycle->body,
            'AERYS_SOCKET_ID'   => $client->id,
            'SERVER_PORT'       => $client->serverPort,
            'SERVER_ADDR'       => $client->serverAddress,
            'SERVER_NAME'       => $serverName,
            'SERVER_PROTOCOL'   => $requestCycle->protocol,
            'REMOTE_ADDR'       => $client->clientAddress,
            'REMOTE_PORT'       => $client->clientPort,
            'HTTPS'             => $client->isEncrypted,
            'REQUEST_METHOD'    => $requestCycle->method,
            'REQUEST_URI'       => $requestCycle->uri,
            'REQUEST_URI_PATH'  => $requestCycle->uriPath,
            'QUERY_STRING'      => $requestCycle->uriQuery
        ];

        if (!empty($__headers['CONTENT-TYPE'])) {
            $request['CONTENT_TYPE'] = $__headers['CONTENT-TYPE'][0];
            unset($__headers['CONTENT-TYPE']);
        }

        if (!empty($__headers['CONTENT-LENGTH'])) {
            $request['CONTENT_LENGTH'] = $__headers['CONTENT-LENGTH'][0];
            unset($__headers['CONTENT-LENGTH']);
        }

        $request['QUERY'] = $requestCycle->uriQuery ? parse_str($requestCycle->uriQuery, $request['QUERY']) : [];

        // @TODO Add cookie parsing
        //if (!empty($headers['COOKIE']) && ($cookies = $this->parseCookies($headers['COOKIE']))) {
        //    $request['COOKIE'] = $cookies;
        //}

        // @TODO Add multipart entity parsing

        // @TODO Maybe put headers into their own "HEADERS" array
        foreach ($__headers as $field => $value) {
            $field = 'HTTP_' . str_replace('-', '_', $field);
            $value = isset($value[1]) ? implode(',', $value) : $value[0];
            $request[$field] = $value;
        }

        $requestCycle->request = $request;

        if (!$isValidHost) {
            $requestCycle->response = [
                'status' => Status::BAD_REQUEST,
                'reason' => 'Bad Request: Invalid Host',
                'body'   => '<html><body><h1>400 Bad Request: Invalid Host</h1></body></html>',
            ];
        } elseif (!isset($this->allowedMethods[$__method])) {
            $requestCycle->response = [
                'status' => Status::METHOD_NOT_ALLOWED,
                'header' => [
                    'Connection: close',
                    'Allow: ' . implode(',', array_keys($this->allowedMethods)),
                ],
                'body'   => '<html><body><h1>405 Method Not Allowed</h1></body></html>',
            ];
        } elseif ($__method === 'TRACE' && empty($requestCycle->headers['MAX_FORWARDS'])) {
            // @TODO Max-Forwards needs some additional server flag because that check shouldn't
            // be used unless the server is acting as a reverse proxy
            $requestCycle->response = [
                'status' => Status::OK,
                'header' => ['Content-Type: message/http'],
                'body'   => $__trace,
            ];
        } elseif ($__method === 'OPTIONS' && $requestCycle->uri === '*') {
            $requestCycle->response = [
                'status' => Status::OK,
                'header' => ['Allow: ' . implode(',', array_keys($this->allowedMethods))],
            ];
        } elseif ($this->requireBodyLength && $__headersOnly && empty($requestCycle->headers['CONTENT-LENGTH'])) {
            $requestCycle->response = [
                'status' => Status::LENGTH_REQUIRED,
                'reason' => 'Content Length Required',
                'header' => ['Connection: close'],
            ];
        }

        return $requestCycle;
    }

    private function onCompletedRequest($client, array $parsedRequest) {
        unset($this->keepAliveTimeouts[$client->id]);

        if ($requestCycle = $client->partialCycle) {
            $this->updateRequestAfterEntity($requestCycle, $parsedRequest['headers']);
        } else {
            $requestCycle = $this->initializeCycle($client, $parsedRequest);
        }

        if ($requestCycle->response) {
            $this->hydrateResponderPipeline($requestCycle);
        } else {
            $this->invokeHostApplication($requestCycle);
        }
    }

    private function updateRequestAfterEntity($requestCycle, array $parsedHeadersArray) {
        $requestCycle->client->partialCycle = NULL;

        if ($needsNewRequestId = $requestCycle->expectsContinue) {
            $requestCycle->requestId = ++$this->lastRequestId;
            $requestCycle->client->cycles[$requestCycle->requestId] = $requestCycle;
        }

        if (isset($requestCycle->request['HTTP_TRAILERS'])) {
            $this->updateTrailerHeaders($requestCycle, $parsedHeadersArray);
        }

        $contentType = isset($requestCycle->request['CONTENT_TYPE'])
            ? $requestCycle->request['CONTENT_TYPE']
            : NULL;

        if (stripos($contentType, 'application/x-www-form-urlencoded') === 0) {
            $bufferedBody = stream_get_contents($requestCycle->body);
            parse_str($bufferedBody, $requestCycle->request['FORM']);
            rewind($requestCycle->body);
        }
    }

    /**
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.40
     */
    private function updateTrailerHeaders($requestCycle, array $headers) {
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
                $requestCycle->request[$key] = $value;
            }
        }
    }

    private function invokeHostApplication($requestCycle) {
        try {
            $application = $requestCycle->host->getApplication();
            $response = $application($requestCycle->request);
            $this->assignResponse($requestCycle, $response);
        } catch (\Exception $exception) {
            $this->assignExceptionResponse($requestCycle, $exception);
        }
    }

    private function assignResponse($requestCycle, $response) {
        if ($requestCycle->client->isGone) {
            // If the client disconnected while we were generating
            // the response there's nothing else to do.
            return;
        }

        switch (gettype($response)) {
            case 'string':
                $requestCycle->response = [
                    'status' => 200,
                    'reason' => '',
                    'header' => [],
                    'body'   => $response,
                ];
                $this->hydrateResponderPipeline($requestCycle);
                break;
            case 'array':
                $baseResponse = ['status' => 200, 'reason' => '', 'header' => [], 'body' => ''];
                $response = array_merge($baseResponse, $response);
                if (is_string($response['body'])) {
                    $requestCycle->response = $response;
                    $this->hydrateResponderPipeline($requestCycle);
                } else {
                    $this->assignExceptionResponse($requestCycle, new \UnexpectedValueException(
                        sprintf(
                            "Invalid response body type: %s",
                            gettype($response['body'])
                        )
                    ));
                }
                break;
            case 'object':
                $this->assignObjectResponse($requestCycle, $response);
                break;
            default:
                $this->assignExceptionResponse($requestCycle, new \UnexpectedValueException(
                    "Invalid response type: {$type}"
                ));
        }
    }

    private function assignObjectResponse($requestCycle, $response) {
        if ($response instanceof Responder || $response instanceof \Generator) {
            $requestCycle->response = $response;
            $this->hydrateResponderPipeline($requestCycle);
        } elseif ($response instanceof Promise) {
            $response->when(function($error, $response) use ($requestCycle) {
                if ($error) {
                    $this->assignExceptionResponse($requestCycle, $error);
                } else {
                    $this->assignResponse($requestCycle, $response);
                }
            });
        } else {
            $this->assignExceptionResponse($requestCycle, new \UnexpectedValueException(
                sprintf(
                    "Invalid response object; Responder, Generator or Promise expected. %s provided",
                    get_class($response)
                )
            ));
        }
    }

    private function assignExceptionResponse($requestCycle, \Exception $exception) {
        if (empty($this->debug)) {
            // @TODO Log the error here. For now we'll just send it to STDERR:
            @fwrite(STDERR, $exception);
        }

        $displayMsg = $this->debug
            ? "<pre>{$exception}</pre>"
            : '<p>Something went terribly wrong</p>';

        $status = Status::INTERNAL_SERVER_ERROR;
        $reason = Reason::HTTP_500;
        $body = "<html><body><h1>{$status} {$reason}</h1><p>{$displayMsg}</p></body></html>";
        $requestCycle->response = [
            'status' => $status,
            'reason' => $reason,
            'body'   => $body,
        ];

        $this->hydrateResponderPipeline($requestCycle);
    }

    private function hydrateResponderPipeline($requestCycle) {
        // @TODO Retrieve and execute HostDefinition::getBeforeResponse() here ...

        $client = $requestCycle->client;

        foreach ($client->cycles as $requestId => $requestCycle) {
            if (isset($client->pipeline[$requestId])) {
                continue;
            } elseif ($requestCycle->response) {
                $client->pipeline[$requestId] = $this->makeResponder($requestCycle);
            } else {
                break;
            }
        }

        // IMPORTANT: reset the $client->cycles array to avoid breaking the pipeline order
        reset($client->cycles);

        if ($client->pendingResponder || !($responder = current($client->pipeline))) {
            // If we already have a pending responder in progress we have to wait until
            // it's complete before starting the next one. Otherwise we need to ensure
            // the first request in the pipeline has a responder before proceeding.
            return;
        }

        $client->pendingResponder = $responder;
        $promise = $responder->write();

        if ($client->isGone) {
            // Allow responders to export the client socket
            return;
        }

        $promise->when(function($error, $mustClose) use ($client) {
            $client->pendingResponder = null;

            if (empty($error)) {
                return $this->afterResponseWrite($client, $mustClose);
            }

            if (!$error instanceof ClientGoneException) {
                // @TODO Log $e
                // Write failure occurred for some reason other than a premature client
                // disconnect. This represents an error in our program and requires logging.
            }

            // Always close the connection if an error occurred while writing
            $this->closeClient($client);
        });
    }

    private function makeResponder($requestCycle) {
        $struct = new ResponderStruct;
        $struct->server = $this;
        $struct->debug = $this->debug;
        $struct->socket = $requestCycle->client->socket;
        $struct->reactor = $this->reactor;
        $struct->writeWatcher = $requestCycle->client->writeWatcher;
        $struct->httpDate = $this->httpDateNow;
        $struct->serverToken = $this->sendServerToken ? self::NAME : null;
        $struct->defaultContentType = $this->defaultContentType;
        $struct->defaultTextCharset = $this->defaultTextCharset;
        $struct->request = $requestCycle->request;
        $struct->response = $response = $requestCycle->response;

        $protocol = $requestCycle->request['SERVER_PROTOCOL'];
        $reqConnHdr = isset($requestCycle->request['HTTP_CONNECTION'])
            ? $requestCycle->request['HTTP_CONNECTION']
            : null;

        if ($this->disableKeepAlive || $this->state === self::STOPPING) {
            // If keep-alive is disabled or the server is stopping we always close
            // after the response is written.
            $struct->mustClose = true;
        } elseif ($this->maxRequests > 0 && $requestCycle->client->requestCount >= $this->maxRequests) {
            // If the client has exceeded the max allowable requests per connection
            // we always close after the response is written.
            $struct->mustClose = true;
        } elseif (isset($reqConnHdr)) {
            // If the request indicated a close preference we agree to that. If the request uses
            // HTTP/1.0 we may still have to close if the response content length is unknown.
            // This potential need must be determined based on whether or not the response
            // content length is known at the time output starts.
            $struct->mustClose = (stripos($reqConnHdr, 'close') !== false);
        } elseif ($protocol < 1.1) {
            // HTTP/1.0 defaults to a close after each response if not otherwise specified.
            $struct->mustClose = true;
        } else {
            $struct->mustClose = false;
        }

        if (!$struct->mustClose) {
            $keepAlive = "timeout={$this->keepAliveTimeout}, max=";
            $keepAlive.= $this->maxRequests - $requestCycle->client->requestCount;
            $struct->keepAlive = $keepAlive;
        }

        if ($response instanceof Responder) {
            $responder = $response;
        } elseif ($response instanceof \Generator) {
            $responder = new GeneratorResponder;
        } else {
            $responder = new StringResponder;
        }

        $responder->prepare($struct);

        return $responder;
    }

    private function afterResponseWrite(Client $client, $shouldClose) {
        if ($client->isGone) {
            // Nothing to do; the client socket has already disconnected or been exported.
            return;
        }

        $requestId = key($client->cycles);
        $requestCycle = $client->cycles[$requestId];

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
            $this->clearClientReferences($client, $decrementClientCount = false);
            $closeCallback = function() use ($socketId) {
                $this->closeExportedSocket($socketId);
            };

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
            $this->stopFuture->succeed();
        }
    }

    private function doSocketClose($socket) {
        if (!is_resource($socket)) {
            return;
        }

        $socketId = (int) $socket;

        if (isset(stream_context_get_options($socket)['ssl'])) {
            @stream_socket_enable_crypto($socket, FALSE);
        }

        if ($this->socketSoLingerZero) {
            $this->closeSocketWithSoLingerZero($socket);
        } else {
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
        $seconds = (int) $seconds;
        if ($seconds < -1) {
            $seconds = 10;
        }
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

    private function setSendServerToken($boolFlag) {
        $this->sendServerToken = (bool) $boolFlag;
    }

    private function setNormalizeMethodCase($boolFlag) {
        $this->normalizeMethodCase = (bool) $boolFlag;
    }

    private function setRequireBodyLength($boolFlag) {
        $this->requireBodyLength = (bool) $boolFlag;
    }

    private function setSocketSoLingerZero($boolFlag) {
        $boolFlag = (bool) $boolFlag;

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
