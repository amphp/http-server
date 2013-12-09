<?php

namespace Aerys;

use Alert\Reactor,
    Aerys\Parsing\Parser,
    Aerys\Parsing\ParserFactory,
    Aerys\Parsing\ParseException,
    Aerys\Writing\WriterFactory,
    Aerys\Writing\ResourceException;

class Server {

    const STOPPED = 0;
    const STARTED = 1;
    const ON_HEADERS = 2;
    const BEFORE_RESPONSE = 3;
    const AFTER_RESPONSE = 4;
    const PAUSED = 5;
    const STOPPING = 6;
    const NEED_STOP_PERMISSION = 7;

    private $state;
    private $reactor;
    private $hostBinder;
    private $parserFactory;
    private $writerFactory;
    private $hosts;
    private $listeningSockets = [];
    private $acceptWatchers = [];
    private $pendingTlsWatchers = [];
    private $clients = [];
    private $requestIdMap = [];
    private $exportedSocketIdMap = [];
    private $cachedClientCount = 0;
    private $lastRequestId = 0;
    private $inProgressRequestId;
    private $isBeforeResponse = FALSE;
    private $isAfterResponse = FALSE;

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
    private $serverToken = 'Aerys/0.1.0-devel';
    private $showErrors = TRUE;
    private $stopTimeout = -1;

    private $isExtSocketsEnabled;

    function __construct(
        Reactor $reactor,
        HostBinder $hb = NULL,
        ParserFactory $pf = NULL,
        WriterFactory $wf = NULL
    ) {
        $this->state = self::STOPPED;
        $this->reactor = $reactor;
        $this->hostBinder = $hb ?: new HostBinder;
        $this->parserFactory = $pf ?: new ParserFactory;
        $this->writerFactory = $wf ?: new WriterFactory;
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
    function start($hostOrCollection, array $listeningSockets = []) {
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

    /**
     *
     */
    function addObserver($event, callable $observer, array $options = []) {
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

    /**
     *
     */
    function removeObserver(ServerObservation $observation) {
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
    function preventStop() {
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
    function allowStop($stopId) {
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
    function stop($timeout = NULL) {
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
        if ($this->inProgressRequestId) {
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
        $asgiResponse = [
            $status = 503,
            $reason = 'Service Unavailable',
            $headers = ['Connection: close'],
            $body = '<html><body><h1>503 Service Unavailable</h1></body></html>'
        ];

        if ($this->clients && $this->timeout !== -1) {
            $forceStopper = function() { $this->forceStop(); };
            $this->forceStopWatcher = $this->reactor->once($forceStopper, $this->timeout);
        }

        foreach ($this->clients as $client) {
            $this->stopClient($client, $asgiResponse);
        }

        while ($this->state === self::STOPPING) {
            $this->reactor->tick();
        }
    }

    private function stopClient(Client $client, array $asgiResponse) {
        if ($client->requests) {
            $unassignedRequestIds = array_keys(array_diff_key($client->requests, $client->pipeline));
            foreach ($unassignedRequestIds as $requestId) {
                $this->setResponse($requestId, $asgiResponse);
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

        $body = '<html><body><h1>500 Internal Server Error</h1><hr/><p>%s</p></body></html>';
        $body = sprintf($body, $errorMsg);
        $headers = [
            'Content-Type: text/html; charset=utf-8',
            'Content-Length: ' . strlen($body),
            'Connection: close'
        ];
        $asgiResponse = [500, 'Internal Server Error', $headers, $body];

        $this->setResponse($this->inProgressRequestId, $asgiResponse);
        $this->inProgressRequestId = NULL;
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

        $onHeaders = function($requestArr) use ($client) {
            $this->afterRequestHeaders($client, $requestArr);
        };

        $client->parser = $this->parserFactory->makeParser();
        $client->parser->setOptions([
            'maxHeaderBytes' => $this->maxHeaderBytes,
            'maxBodyBytes' => $this->maxBodyBytes,
            'beforeBody' => $onHeaders
        ]);

        $onReadable = function() use ($client) { $this->doClientRead($client); };
        $client->readWatcher = $this->reactor->onReadable($socket, $onReadable);

        $onWritable = function() use ($client) { $this->doClientWrite($client); };
        $client->writeWatcher = $this->reactor->onWritable($socket, $onWritable, $enableNow = FALSE);

        $this->clients[$socketId] = $client;
    }

    private function parseSocketName($name) {
        $portStartPos = strrpos($name, ':');
        $addr = substr($name, 0, $portStartPos);
        $port = substr($name, $portStartPos + 1);

        return [$addr, $port];
    }

    private function doClientRead(Client $client) {
        $data = @fread($client->socket, $this->readGranularity);

        if ($data || $data === '0') {
            $this->renewKeepAliveTimeout($client->id);
            $this->parseDataReadFromSocket($client, $data);
        } elseif (!is_resource($client->socket) || @feof($client->socket)) {
            $this->closeClient($client);
        }
    }

    private function parseDataReadFromSocket(Client $client, $data) {
        try {
            while ($requestArr = $client->parser->parse($data)) {
                $this->onRequest($client, $requestArr);
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

    private function afterRequestHeaders(Client $client, array $requestArr) {
        if ($request = $this->initializeRequest($client, $requestArr)) {
            $this->beforeRequestBody($client, $request);
        }
    }

    private function beforeRequestBody(Client $client, Request $request) {
        $requestId = $request->getId();
        $asgiEnv = $request->getAsgiEnv();

        if ($this->requireBodyLength && empty($asgiEnv['CONTENT_LENGTH'])) {
            $asgiResponse = [Status::LENGTH_REQUIRED, Reason::HTTP_411, ['Connection: close'], NULL];
            return $this->setResponse($requestId, $asgiResponse);
        }

        $client->preBodyRequest = $request;
        $client->requests[$requestId] = $request;
        $host = $request->getHost();

        $this->invokeRequestObservers(self::ON_HEADERS, $host, $requestId);

        $asgiResponse = $request->getAsgiResponse();

        if (!$asgiResponse && $request->expects100Continue()) {
            $asgiResponse = [Status::CONTINUE_100, Reason::HTTP_100, [], NULL];
            $request->setAsgiResponse($asgiResponse);
        }

        if ($asgiResponse) {
            $this->writePipelinedResponses($client);
        }
    }

    private function invokeRequestObservers($event, Host $host, $requestId) {
        if (empty($this->observations[$event])) {
            return;
        }

        foreach ($this->observations[$event] as $observation) {
            if ($host->matches($observation->getHost())) {
                $callback = $observation->getCallback();
                $callback($requestId);
            }
        }
    }

    private function initializeRequest(Client $client, array $requestArr) {
        $client->requestCount++;
        $requestId = ++$this->lastRequestId;
        $method = $this->normalizeMethodCase
            ? strtoupper($requestArr['method'])
            : $requestArr['method'];

        $request = (new Request($requestId))
            ->setClient($client)
            ->setTrace($requestArr['trace'])
            ->setProtocol($requestArr['protocol'])
            ->setMethod($method)
            ->setUri($requestArr['uri'])
            ->setHeaders($requestArr['headers'])
            ->setBody($requestArr['body']);

        $this->requestIdMap[$requestId] = $request;

        if (!$this->assignRequestHost($request)) {
            $asgiResponse = $this->generateInvalidHostNameResponse();
        } elseif (!in_array($method, $this->allowedMethods)) {
            $asgiResponse = $this->generateMethodNotAllowedResponse();
        } elseif ($method === 'TRACE' && !$request->hasHeader('Max-Forwards')) {
            $asgiResponse = $this->generateTraceResponse($request->getTrace());
        } elseif ($method === 'OPTIONS' && $request->getUri() === '*') {
            $asgiResponse = $this->generateOptionsResponse();
        } else {
            $asgiResponse = NULL;
        }

        $request->generateAsgiEnv();

        $client->requests[$requestId] = $request;

        if ($asgiResponse) {
            $this->setResponse($requestId, $asgiResponse);
            $initResult = NULL;
        } else {
            $initResult = $request;
        }

        return $initResult;
    }

    private function assignRequestHost(Request $request) {
        if ($host = $this->hosts->selectRequestHost($request)) {
            $isRequestHostValid = TRUE;
        } else {
            $host = $this->hosts->selectDefaultHost();
            $isRequestHostValid = FALSE;
        }

        $request->setHost($host);

        return $isRequestHostValid;
    }

    private function generateTraceResponse($body) {
        $headers = ['Content-Type: message/http'];

        return [Status::OK, Reason::HTTP_200, $headers, $body];
    }

    private function generateInvalidHostNameResponse() {
        $body = "<html><body><h1>400 Bad Request: Invalid Host</h1></body></html>";
        $headers = [
            'Content-Type: text/html; charset=iso-8859-1',
            'Content-Length: ' . strlen($body),
            'Connection: close'
        ];

        return [400, 'Bad Request: Invalid Host', $headers, $body];
    }

    private function generateMethodNotAllowedResponse() {
        $headers = ['Allow: ' . implode(',', $this->allowedMethods)];

        return [405, 'Method Not Allowed', $headers, $body = NULL];
    }

    private function generateOptionsResponse() {
        $headers = ['Allow: ' . implode(',', $this->allowedMethods)];

        return [200, 'OK', $headers, $body = NULL];
    }

    private function onRequest(Client $client, array $requestArr) {
        unset($this->keepAliveTimeouts[$client->id]);

        if ($request = $client->preBodyRequest) {
            $this->onRequestEntityCompletion($client, $requestArr);
            $requestId = $request->getId();
        } elseif ($request = $this->initializeRequest($client, $requestArr)) {
            $requestId = $request->getId();
            $host = $request->getHost();
            $this->invokeRequestObservers(self::ON_HEADERS, $host, $requestId);
        } else {
            return; // Response already assigned
        }

        if (!($request->isComplete() || $request->getAsgiResponse())) {
            $handler = $request->getHostHandler();
            $asgiEnv = $request->getAsgiEnv();
            $this->inProgressRequestId = $requestId;
            $this->invokeRequestHandler($handler, $asgiEnv, $requestId);
            $this->inProgressRequestId = NULL;
        }
    }

    private function onRequestEntityCompletion(Client $client, array $requestArr) {
        $request = $client->preBodyRequest;
        $client->preBodyRequest = NULL;
        $needsNewRequestId = $request->expects100Continue();

        if ($needsNewRequestId) {
            $requestId = ++$this->lastRequestId;
            $this->requestIdMap[$requestId] = $request;
        }

        $request->updateAsgiEnvAfterEntity($requestArr['headers']);
    }

    private function invokeRequestHandler(callable $handler, array $asgiEnv, $requestId) {
        try {
            if ($asgiResponse = $handler($asgiEnv, $requestId)) {
                $this->setResponse($requestId, $asgiResponse);
            }
        } catch (\Exception $e) {
            $errorMsg = $this->showErrors
                ? '<pre>' . $e->__toString() . '</pre>'
                : '<p>Something went terribly wrong.</p>';
            $body = '<html><body><h1>500 Internal Server Error</h1><hr/>%s</body></html>';
            $body = sprintf($body, $errorMsg);
            $headers = [
                'Content-Type: text/html; charset=utf-8',
                'Content-Length: ' . strlen($body)
            ];
            $asgiResponse = [500, 'Internal Server Error', $headers, $body];
            $this->setResponse($requestId, $asgiResponse);
        }
    }

    private function onParseError(Client $client, ParseException $e) {
        $requestId = $client->preBodyRequest
            ? $client->preBodyRequest->getId()
            : $this->generateParseErrorEnv($client, $e);

        $asgiResponse = $this->generateParseErrorResponse($e);

        $this->setResponse($requestId, $asgiResponse);
    }

    private function generateParseErrorEnv(Client $client, ParseException $e) {
        $requestId = ++$this->lastRequestId;
        $requestArr = $e->getParsedMsgArr();
        $request = (new Request($requestId))
            ->setClient($client)
            ->setTrace($requestArr['trace'])
            ->setProtocol($requestArr['protocol'])
            ->setMethod($requestArr['method'])
            ->setUri($requestArr['uri'])
            ->setHeaders($requestArr['headers']);

        $this->assignRequestHost($request);
        $this->requestIdMap[$requestId] = $request;
        $client->requests[$requestId] = $request;
        $client->requestCount++;

        return $requestId;
    }

    private function generateParseErrorResponse(ParseException $e) {
        $status = $e->getCode() ?: 400;
        $reason = $this->getReasonPhrase($status);
        $msg = $e->getMessage();
        $body = "<html><body><h1>{$status} {$reason}</h1><p>{$msg}</p></body></html>";
        $headers = [
            "Date: {$this->httpDateNow}",
            'Content-Type: text/html; charset=utf-8',
            'Content-Length: ' . strlen($body),
            'Connection: close'
        ];

        return [$status, $reason, $headers, $body];
    }

    private function getReasonPhrase($statusCode) {
        $reasonConst = "Aerys\Reason::HTTP_{$statusCode}";

        return defined($reasonConst) ? constant($reasonConst) : '';
    }

    private function processGeneratorYield($requestId, \Generator $generator) {
        try {
            $key = $generator->key();
            $value = $generator->current();

            if (is_callable($value)) {
                $value(function() use ($requestId, $generator) {
                    $generator->send(func_get_args());
                    $this->processGeneratorYield($requestId, $generator);
                });
            } elseif (is_callable($key)) {
                $value = is_array($value) ? $value : [$value];
                array_push($value, function() use ($requestId, $generator) {
                    $generator->send(func_get_args());
                    $this->processGeneratorYield($requestId, $generator);
                });
                call_user_func_array($key, $value);
            } elseif (!isset($value)) {
                throw new \RuntimeException(
                    'Invalid NULL yielded from Generator response'
                );
            } else {
                $this->setResponse($requestId, $value);
            }
        } catch (\Exception $e) {
            $msg = $this->showErrors ? $e->__toString() : 'Something went terribly wrong';
            $status = 500;
            $reason = 'Internal Server Error';
            $body = "<html><body><h1>{$status} {$reason}</h1><p>{$msg}</p></body></html>";

            $this->setResponse($requestId, [$status, $reason, $headers = [], $body]);
        }
    }

    function setResponse($requestId, $asgiResponse) {
        if (!isset($this->requestIdMap[$requestId])) {
            return;
        } elseif ($asgiResponse instanceof \Generator) {
            return $this->processGeneratorYield($requestId, $asgiResponse);
        }

        $request = $this->requestIdMap[$requestId];
        $request->setAsgiResponse($asgiResponse);

        if (!$this->isBeforeResponse) {
            $host = $request->getHost();

            $this->isBeforeResponse = TRUE;
            $this->invokeRequestObservers(self::BEFORE_RESPONSE, $host, $requestId);
            $this->isBeforeResponse = FALSE;

            // We need to make another isset check in case a BeforeResponseMod has exported the socket
            // @TODO clean this up to get rid of the ugly nested if statement
            if (!$request->isComplete()) {
                $client = $request->getClient();
                $this->writePipelinedResponses($client);
            }
        }
    }

    private function doClientWrite(Client $client) {
        try {
            foreach ($client->pipeline as $requestId => $responseWriter) {
                if (!$responseWriter) {
                    break;
                } elseif ($responseWriter->write()) {
                    $this->afterResponse($client, $requestId);
                    // writeWatchers are disabled during afterResponse() processing as needed
                } else {
                    $this->reactor->enable($client->writeWatcher);
                    break;
                }
            }
        } catch (ResourceException $e) {
            $this->closeClient($client);
        }
    }

    private function writePipelinedResponses(Client $client) {
        foreach ($client->requests as $requestId => $request) {
            if (isset($client->pipeline[$requestId])) {
                continue;
            } elseif ($request->hasResponse()) {
                $responseWriter = $this->generatePipelineResponseWriter($request);
                $client->pipeline[$requestId] = $responseWriter;
            } else {
                break;
            }
        }

        reset($client->requests);

        $this->doClientWrite($client);
    }

    private function generatePipelineResponseWriter(Request $request) {
        list($status, $reason, $headers, $body) = $this->normalizeAsgiResponse($request);

        $protocol = $request->getProtocol();
        $reason = ($reason || $reason === '0') ? (' ' . $reason) : '';
        $rawHeaders = "HTTP/{$protocol} {$status}{$reason}\r\n{$headers}\r\n\r\n";
        $client = $request->getClient();

        return $this->writerFactory->make($client->socket, $rawHeaders, $body, $protocol);
    }

    private function normalizeAsgiResponse(Request $request) {
        try {
            $asgiResponse = $this->doResponseNormalization($request);
        } catch (\UnexpectedValueException $e) {
            $status = 500;
            $reason = 'Internal Server Error';
            $body = $e->getMessage();
            $headers = 'Content-Length: ' . strlen($body) .
                "\r\nContent-Type: text/plain;charset=utf-8" .
                "\r\nConnection: close";

            $asgiResponse = [$status, $reason, $headers, $body];
        }

        $request->setAsgiResponse($asgiResponse);

        return $asgiResponse;
    }

    /**
     * @throws \UnexpectedValueException On bad ASGI response
     */
    private function doResponseNormalization(Request $request) {
        $asgiEnv = $request->getAsgiEnv();
        $client = $request->getClient();
        $asgiResponse = $request->getAsgiResponse();

        if (is_array($asgiResponse)) {
            list($status, $reason, $headers, $body) = $asgiResponse;
        } else {
            $status = 200;
            $reason = 'OK';
            $headers = [];
            $body = $asgiResponse;
        }

        $status = (int) $status;
        if ($status < 100 || $status > 599) {
            throw new \UnexpectedValueException(
                'Invalid response status code'
            );
        }

        if ($this->autoReasonPhrase && empty($reason)) {
            $reason = $this->getReasonPhrase($status);
        }

        $headers = $this->stringifyResponseHeaders($headers);

        if ($status >= 200) {
            list($headers, $close) = $this->normalizeConnectionHeader($headers, $asgiEnv, $client);
            list($headers, $close) = $this->normalizeEntityHeaders($headers, $body, $request->isHttp11(), $close);
        } else {
            $close = FALSE;
        }

        if ($this->sendServerToken && stripos($headers, "\r\nServer:") === FALSE) {
            $headers = sprintf("%s\r\nServer: %s", $headers, $this->serverToken);
        }

        if (stripos($headers, "\r\nDate:") === FALSE) {
            $headers = sprintf("%s\r\nDate: %s", $headers, $this->httpDateNow);
        }

        // This MUST happen AFTER content-length/body normalization or headers won't be correct
        if ($request->getMethod() === 'HEAD') {
            $body = NULL;
        }

        $headers = trim($headers);

        $client->closeAfterSend[] = $close;

        $asgiResponse = isset($asgiResponse[4])
            ? [$status, $reason, $headers, $body, $asgiResponse[4]]
            : [$status, $reason, $headers, $body];

        return $asgiResponse;
    }

    private function stringifyResponseHeaders($headers) {
        if (!$headers) {
            $headers = '';
        } elseif (is_array($headers)) {
            $headers = "\r\n" . implode("\r\n", array_map('trim', $headers));
        } elseif (is_string($headers)) {
            $headers = "\r\n" . implode("\r\n", array_map('trim', explode("\n", $headers)));
        } else {
            throw new \UnexpectedValueException(
                'Invalid response headers'
            );
        }

        return $headers;
    }

    /**
     * @TODO Massive FIX needed here. Use Response object to store close flag
     */
    private function normalizeConnectionHeader($headers, $asgiEnv, Client $client) {
        $currentHeaderPos = stripos($headers, "\r\nConnection:");

        if ($currentHeaderPos !== FALSE) {
            $lineEndPos = strpos($headers, "\r\n", $currentHeaderPos + 2);
            $line = substr($headers, $currentHeaderPos, $lineEndPos - $currentHeaderPos);
            //$willClose = stristr($line, 'close');
            $willClose = stristr($headers, 'close');
// @TODO FIX THIS!!!!!
            return [$headers, $willClose];
        }

        if ($this->state === self::STOPPING) {
            $valueToAssign = 'close';
            $willClose = TRUE;
        } elseif ($this->disableKeepAlive) {
            $valueToAssign = 'close';
            $willClose = TRUE;
        } elseif ($this->maxRequests > 0 && $client->requestCount >= $this->maxRequests) {
            $valueToAssign = 'close';
            $willClose = TRUE;
        } elseif (isset($asgiEnv['HTTP_CONNECTION'])) {
            $valueToAssign = stristr($asgiEnv['HTTP_CONNECTION'], 'keep-alive') ? 'keep-alive' : 'close';
            $willClose = ($valueToAssign === 'close');
        } elseif ($asgiEnv['SERVER_PROTOCOL'] !== '1.1') {
            $valueToAssign = 'close';
            $willClose = TRUE;
        } else {
            $valueToAssign = 'keep-alive';
            $willClose = FALSE;
        }

        $newConnectionHeader = "\r\nConnection: {$valueToAssign}";
        $headers .= $newConnectionHeader;

        if (!$willClose) {
            $remainingRequests = $this->maxRequests - $client->requestCount;
            $headers .= "\r\nKeep-Alive: timeout={$this->keepAliveTimeout}, max={$remainingRequests}";
        }

        return [$headers, $willClose];
    }

    private function normalizeEntityHeaders($headers, $body, $isHttp11, $willClose) {
        $hasBody = ($body || $body === '0');

        if (!$hasBody || is_scalar($body)) {
            $headers = $this->normalizeScalarContentLength($headers, $body);
        } elseif (is_resource($body)) {
            $headers = $this->normalizeResourceContentLength($headers, $body);
        } elseif ($body instanceof Writing\MultiPartByteRangeBody) {
            // Headers from the static DocRoot handler are assumed to be correct; no change needed.
        } elseif ($body instanceof \Iterator) {
            $willClose = !$isHttp11;
            $headers = $isHttp11
                ? $this->normalizeIteratorHeadersForChunking($headers)
                : $this->normalizeIteratorHeadersForClose($headers);
        } else {
            throw new \UnexpectedValueException(
                'Invalid response body'
            );
        }

        if (stripos($headers, "\r\nContent-Type:") === FALSE) {
            $headers .= "\r\nContent-Type: {$this->defaultContentType}; charset={$this->defaultTextCharset}";
        }

        return [$headers, $willClose];
    }

    private function normalizeScalarContentLength($headers, $body) {
        $headers = $this->removeContentLengthAndTransferEncodingHeaders($headers);
        $headers .= "\r\nContent-Length: " . strlen($body);

        return $headers;
    }

    private function normalizeResourceContentLength($headers, $body) {
        fseek($body, 0, SEEK_END);
        $bodyLen = ftell($body);
        rewind($body);

        $headers = $this->removeContentLengthAndTransferEncodingHeaders($headers);
        $headers .= "\r\nContent-Length: {$bodyLen}";

        return $headers;
    }

    private function normalizeIteratorHeadersForChunking($headers) {
        $headers = $this->removeContentLengthAndTransferEncodingHeaders($headers);
        $headers.= "\r\nTransfer-Encoding: chunked";

        return $headers;
    }

    private function normalizeIteratorHeadersForClose($headers) {
        $headers = $this->removeContentLengthAndTransferEncodingHeaders($headers);

        $connPos = strpos($headers, "\r\nConnection:");
        $lineEndPos = strpos($headers, "\r\n", $connPos + 2);
        $start = substr($headers, 0, $connPos);
        $end = $lineEndPos ? substr($headers, $lineEndPos) : '';
        $headers = $start . "\r\nConnection: close" . $end;

        return $headers;
    }

    private function removeContentLengthAndTransferEncodingHeaders($headers) {
        $clPos = stripos($headers, "\r\nContent-Length:");
        $tePos = stripos($headers, "\r\nTransfer-Encoding:");

        if ($clPos !== FALSE) {
            $lineEndPos = strpos($headers, "\r\n", $clPos + 2);
            $start = substr($headers, 0, $clPos);
            $end = $lineEndPos ? substr($headers, $lineEndPos) : '';
            $headers = $start . $end;
        }

        if ($tePos !== FALSE) {
            $lineEndPos = strpos($headers, "\r\n", $tePos + 2);
            $start = substr($headers, 0, $tePos);
            $end = $lineEndPos ? substr($headers, $lineEndPos) : '';
            $headers = $start . $end;
        }

        return $headers;
    }

    private function afterResponse(Client $client, $requestId) {
        $request = $this->requestIdMap[$requestId];
        $request->markComplete();
        $host = $request->getHost();
        $asgiEnv = $request->getAsgiEnv();
        $asgiResponse = $request->getAsgiResponse();

        list($status, $reason) = $asgiResponse;

        $this->isAfterResponse = TRUE;
        $this->invokeRequestObservers(self::AFTER_RESPONSE, $host, $requestId);
        $this->isAfterResponse = FALSE;

        if ($status === Status::SWITCHING_PROTOCOLS) {
            $this->clearClientReferences($client);
            $upgradeCallback = $asgiResponse[4];
            $upgradeCallback($client->socket, $asgiEnv);
        } elseif (array_shift($client->closeAfterSend)) {
            $this->closeClient($client);
        } else {
            $this->shiftClientPipeline($client, $requestId);
        }
    }

    private function clearClientReferences(Client $client) {
        if ($client->requests) {
            foreach (array_keys($client->requests) as $requestId) {
                unset($this->requestIdMap[$requestId]);
            }
        }

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
            $client->requests[$requestId],
            $this->requestIdMap[$requestId]
        );

        // Disable active onWritable stream watchers if the pipeline is no longer write-ready
        if (!current($client->pipeline)) {
            $this->reactor->disable($client->writeWatcher);
            $this->renewKeepAliveTimeout($client->id);
        }
    }

    /**
     * @TODO Add docblocks
     */
    function setRequest($requestId, array $asgiEnv) {
        if (isset($this->requestIdMap[$requestId])) {
            $request = $this->requestIdMap[$requestId];
        } else {
            throw new \DomainException(
                "Unknown request ID: {$requestId}"
            );
        }

        $request->setAsgiEnv($asgiEnv);
    }

    /**
     * @TODO Add docblocks
     */
    function getRequest($requestId) {
        if (isset($this->requestIdMap[$requestId])) {
            $request = $this->requestIdMap[$requestId];
        } else {
            throw new \DomainException(
                "Unknown request ID: {$requestId}"
            );
        }

        return $request->getAsgiEnv();
    }

    /**
     * @TODO Add docblocks
     */
    function getTrace($requestId) {
        if (isset($this->requestIdMap[$requestId])) {
            $request = $this->requestIdMap[$requestId];
        } else {
            throw new \DomainException(
                "Unknown request ID: {$requestId}"
            );
        }

        return $request->getTrace();
    }

    /**
     * @TODO Add docblocks
     */
    function getResponse($requestId) {
        if (isset($this->requestIdMap[$requestId])) {
            $request = $this->requestIdMap[$requestId];
        } else {
            throw new \DomainException(
                "Unknown request ID: {$requestId}"
            );
        }

        return $request->getResponse();
    }

    /**
     * Export a socket from the HTTP server
     *
     * Note that exporting a socket removes all references to its existince from the HTTP server.
     * If any HTTP requests remain outstanding in the client's pipeline they will go unanswered. As
     * far as the HTTP server is concerned the socket no longer exists. The only exception to this
     * rule is the Server::$cachedClientCount variable: _IT WILL NOT BE DECREMENTED_. This is a
     * safety measure. When code that exports sockets is ready to close those sockets IT MUST
     * pass the relevant socket resource back to the Server::closeExportedSocket() method or all
     * allowed client slots will eventually max out. This requirement also allows exported
     * sockets to take advantage of HTTP server options such as socketSoLingerZero.
     *
     * @param int $requestId
     * @throws \DomainException On unknown request ID
     * @return resource Returns the socket stream associated with the specified request ID
     */
    function exportSocket($requestId) {
        if (isset($this->requestIdMap[$requestId])) {
            $request = $this->requestIdMap[$requestId];
        } else {
            throw new \DomainException(
                "Unknown request ID: {$requestId}"
            );
        }

        $client = $request->getClient();
        $socket = $client->socket;
        $socketId = (int) $socket;
        $this->clearClientReferences($client);
        $this->cachedClientCount++; // It was decremented when references were cleared; take it back.
        $this->exportedSocketIdMap[$socketId] = $socket;

        return $socket;
    }

    /**
     * Retrieve information about the socket associated with a given request ID
     *
     * @param $requestId
     * @return array Returns an array of information about the socket underlying the request ID
     */
    function querySocket($requestId) {
        if (isset($this->requestIdMap[$requestId])) {
            $request = $this->requestIdMap[$requestId];
        } else {
            throw new \DomainException(
                "Unknown request ID: {$requestId}"
            );
        }

        return $request->getClientSocketInfo();
    }

    /**
     * Must be called to close/unload the socket once exporting code is finished with the resource
     *
     * @param resource $socket An exported socket resource
     * @return void
     */
    function closeExportedSocket($socket) {
        $socketId = (int) $socket;
        if (isset($this->exportedSocketIdMap[$socketId])) {
            unset($this->exportedSocketIdMap[$socketId]);
            $this->cachedClientCount--;
            $this->doSocketClose($socket);
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
     */
    function logError($errorMsg) {
        $errorMsg = trim($errorMsg) . PHP_EOL;

        if (@flock($this->errorStream, LOCK_EX)) {
            @fwrite($this->errorStream, $errorMsg);
            @fflush($this->errorStream);
            @flock($fp, LOCK_UN);
        } else {
            // @TODO Maybe? Not sure how to handle this ...
        }
    }

    /**
     * Set multiple server options at once
     *
     * @param array $options
     * @throws \DomainException On unrecognized option key
     * @return void
     */
    function setAllOptions(array $options) {
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
    function setOption($option, $value) {
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
        $this->hosts->setDefaultHost($hostId);
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
    function getOption($option) {
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
                return $this->hosts->getDefaultHostId(); break;
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
    function getAllOptions() {
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
            'defaultHost'           => $this->hosts ? $this->hosts->getDefaultHostId() : NULL,
            'showErrors'            => $this->showErrors,
            'stopTimeout'           => $this->stopTimeout
        ];
    }

    function __destruct() {
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
