<?php

namespace Aerys;

use Alert\Reactor,
    Aerys\Parsing\Parser,
    Aerys\Parsing\MessageParser,
    Aerys\Parsing\PeclMessageParser,
    Aerys\Parsing\ParseException,
    Aerys\Writing\WriterFactory,
    Aerys\Writing\ResourceException;

class Server {
    
    const SERVER_SOFTWARE = 'Aerys/0.1.0-devel';
    const HTTP_DATE = 'D, d M Y H:i:s T';
    
    const SILENT = 0;
    const QUIET = 1;
    const LOUD = 2;
    
    private $reactor;
    private $hosts = [];
    private $serverSockets = [];
    private $serverAcceptWatchers = [];
    private $pendingTlsWatchers = [];
    private $isServerRunning = FALSE;
    private $isServerPaused = FALSE;
    private $isInsideBeforeResponseModLoop = FALSE;
    private $isInsideAfterResponseModLoop = FALSE;
    private $clients = [];
    private $requestIdClientMap = [];
    private $cachedClientCount = 0;
    private $lastRequestId = 0;
    private $exportedSocketIds = [];
    
    private $verbosity = self::QUIET;
    private $logErrorsTo = 'php://stderr';
    private $maxConnections = 1500;
    private $maxRequests = 150;
    private $keepAliveTimeout = 10;
    private $defaultContentType = 'text/html';
    private $defaultTextCharset = 'utf-8';
    private $autoReasonPhrase = TRUE;
    private $sendServerToken = FALSE;
    private $disableKeepAlive = FALSE;
    private $socketSoLingerZero = FALSE;
    private $normalizeMethodCase = TRUE;
    private $alwaysAddDateHeader = FALSE;
    private $requireBodyLength = TRUE;
    private $maxHeaderBytes = 8192;
    private $maxBodyBytes = 2097152;
    private $allowedMethods = ['GET', 'HEAD', 'OPTIONS', 'TRACE', 'PUT', 'POST', 'PATCH', 'DELETE'];
    private $defaultHost;
    
    // @TODO Add option setters for the following settings
    private $cryptoType = STREAM_CRYPTO_METHOD_TLS_SERVER;
    // ------------------------- //
    
    private $errorStream;
    private $isExtHttpEnabled;
    private $isExtSocketsEnabled;
    private $isExtOpensslEnabled;
    private $socketReadGranularity = 262144;
    
    private $now;
    private $keepAliveWatcher;
    private $keepAliveTimeouts = [];
    private $keepAliveWatcherInterval = 1;
    
    function __construct(Reactor $reactor, WriterFactory $wf = NULL) {
        $this->reactor = $reactor;
        $this->writerFactory = $wf ?: new WriterFactory;
        
        $this->isExtHttpEnabled = extension_loaded('http');
        $this->isExtSocketsEnabled = extension_loaded('sockets');
        $this->isExtOpensslEnabled = extension_loaded('openssl');
        
        $this->allowedMethods = array_combine($this->allowedMethods, array_fill(0, count($this->allowedMethods), 1));
        
        $this->registerKeepAliveWatcher();
    }
    
    private function registerKeepAliveWatcher() {
        $this->now = time();
        $this->keepAliveWatcher = $this->reactor->repeat(function() {
            $this->timeoutKeepAlives();
        }, $this->keepAliveWatcherInterval);
    }
    
    private function timeoutKeepAlives() {
        $this->now = $now = time();
        foreach ($this->keepAliveTimeouts as $socketId => $expiryTime) {
            if ($expiryTime <= $now) {
                $client = $this->clients[$socketId];
                $this->closeClient($client);
            } else {
                break;
            }
        }
    }
    
    private function renewKeepAliveTimeout($socketId) {
        unset($this->keepAliveTimeouts[$socketId]);
        $this->keepAliveTimeouts[$socketId] = $this->now + $this->keepAliveTimeout;
    }
    
    /**
     * Register a Host to handle requests to a given address or host name
     * 
     * @param Host $host A host definition for responding to client requests
     * @return void
     */
    function registerHost(Host $host) {
        $hostId = $host->getId();
        $this->hosts[$hostId] = $host;
    }
    
    /**
     * Register a Mod for all hosts matching the specified $hostId
     * 
     * Host IDs can match in multiple different ways:
     * 
     * - *              Applies mod to ALL currently registered hosts
     * - *:80           Applies mod to ALL currently registered hosts listening on port 80
     * - 127.0.0.1:*    Applies mod to ALL currently registered hosts listening on any port at 127.0.0.1
     * - mysite.com:80  Applies mod to ONLY mysite.com on port 80
     * 
     * @param string $hostId The Host ID matching string
     * @param \Aerys\Mods\Mod $mod The mod instance to register
     * @param array $priorityMap Optionally specify mod invocation priority values
     * @throws \DomainException If no registered hosts match the specified $hostId
     * @return void
     */
    function registerMod($hostId, $mod, array $priorityMap = []) {
        foreach ($this->selectApplicableHostsById($hostId) as $host) {
            $host->registerMod($mod, $priorityMap);
        }
    }
    
    private function selectApplicableHostsById($hostId) {
        if ($hostId === '*') {
            $applicableHosts = $this->hosts;
        } elseif (substr($hostId, 0, 2) === '*:') {
            $port = substr($hostId, 2);
            $applicableHosts = array_filter($this->hosts, function($host) use ($port) {
                return ($port === '*' || $host->getPort() == $port);
            });
        } elseif (substr($hostId, -2) === ':*') {
            $addr = substr($hostId, 0, -2);
            $applicableHosts = array_filter($this->hosts, function($host) use ($addr) {
                return ($addr === '*' || $host->getAddress() === $addr);
            });
        } elseif (isset($this->hosts[$hostId])) {
            $applicableHosts = [$this->hosts[$hostId]];
        } else {
            // @TODO Determine most appropriate exception to throw here
            throw new \DomainException(
                "No currently registered Hosts match the specified ID: {$hostId}"
            );
        }
        
        return $applicableHosts;
    }
    
    /**
     * Temporarily stop accepting new connections but do not unbind the socket servers
     * 
     * @return void
     */
    function pause() {
        if ($this->isServerRunning && !$this->isServerPaused) {
            foreach ($this->serverAcceptWatchers as $watcher) {
                $this->reactor->disable($watcher);
            }
            $this->isServerPaused = TRUE;
        }
    }
    
    /**
     * Resume accepting new connections on all bound socket servers
     * 
     * @return void
     */
    function resume() {
        if ($this->isServerRunning && $this->isServerPaused) {
            foreach ($this->serverAcceptWatchers as $watcher) {
                $this->reactor->enable($watcher);
            }
            $this->isServerPaused = FALSE;
        }
    }
    
    /**
     * Stop accepting client connections and unbind any socket servers
     * 
     * @return void
     */
    function stop() {
        if ($this->isServerRunning) {
            $this->cancelServerAcceptWatchers();
            $this->closeServerSockets();
            $this->isServerRunning = FALSE;
            $this->isServerPaused = FALSE;
        }
    }
    
    private function cancelServerAcceptWatchers() {
        foreach ($this->serverAcceptWatchers as $watcher) {
            $this->reactor->cancel($watcher);
        }
        
        $this->serverAcceptWatchers = [];
    }
    
    private function closeServerSockets() {
        foreach ($this->serverSockets as $socket) {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
        
        $this->serverSockets = [];
    }
    
    /**
     * Release the hounds!
     * 
     * @throws \LogicException If no hosts have been registered to listen for requests
     * @return void
     */
    function start() {
        if (!$this->isServerRunning && $this->hosts) {
            $this->errorStream = $this->logErrorsTo ? fopen($this->logErrorsTo, 'ab+') : STDERR;
            $this->bindListeningSockets();
            $this->isServerRunning = TRUE;
            $this->reactor->run();
            $this->isServerRunning = FALSE;
        } elseif (!$this->hosts) {
            throw new \LogicException(
                'Cannot start server: no hosts registered'
            );
        }
    }
    
    /**
     * @TODO Track which addresses have TLS enabled and prevent conflicts in multi-host environments
     */
    private function bindListeningSockets() {
        $boundServers = [];
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        
        foreach ($this->hosts as $host) {
            $context = $host->getTlsContext();
            $isEncrypted = $host->hasTlsDefinition();
            $onAcceptableClient = $isEncrypted
                ? function($watcherId, $serverSocket) { $this->acceptTls($serverSocket); }
                : function($watcherId, $serverSocket) { $this->accept($serverSocket); };
                
            $isWildcardIp = $host->hasWildcardAddress();
            
            // @TODO Allow for IPV6 wildcard host, replace literal wildcard address w/ constant
            $ip = $isWildcardIp ? '0.0.0.0' : $host->getAddress();
            $port = $host->getPort();
            $address = "tcp://{$ip}:{$port}";
            
            if ($isEncrypted && !$this->isExtOpensslEnabled) {
                throw new \RuntimeException(
                    'Cannot enable crypto on ' . $host->getId() .'; openssl extension not loaded'
                );
            }
            
            if (isset($boundServers[$address])) {
                continue;
            } elseif ($serverSocket = @stream_socket_server($address, $errNo, $errStr, $flags, $context)) {
                stream_set_blocking($serverSocket, FALSE);
                $serverId = (int) $serverSocket;
                $this->serverSockets[$serverId] = $serverSocket;
                $acceptWatcher = $this->reactor->onReadable($serverSocket, $onAcceptableClient);
                $this->serverAcceptWatchers[$serverId] = $acceptWatcher;
            } else {
                throw new \RuntimeException(
                    "Failed binding server to $address: [Error# $errNo] $errStr"
                );
            }
            
            if ($this->verbosity && !isset($boundServers[$address])) {
                $userFriendlyAddress = substr(str_replace('0.0.0.0', '*', $address), 6);
                echo 'Listening for HTTP traffic on ', $userFriendlyAddress, PHP_EOL;
            }
            
            $boundServers[$address] = TRUE;
        }
        
        reset($this->hosts);
    }
    
    private function accept($serverSocket) {
        while ($clientSock = @stream_socket_accept($serverSocket, $timeout = 0)) {
            $this->cachedClientCount++;
            $this->onClient($clientSock);
            if ($this->cachedClientCount >= $this->maxConnections) {
                $this->pause();
                break;
            }
        }
    }
    
    private function acceptTls($serverSocket) {
        $acceptTimeout = 0;
        
        while ($clientSock = @stream_socket_accept($serverSocket, $acceptTimeout)) {
            if (++$this->cachedClientCount === $this->maxConnections) {
                $this->pause();
            }
            
            $clientId = (int) $clientSock;
            $this->pendingTlsWatchers[$clientId] = NULL;
            
            if (!$this->doTlsHandshake($clientSock)) {
                $watcher = $this->reactor->onReadable($clientSock, function() use ($clientSock) {
                    $this->doTlsHandshake($clientSock);
                });
                
                $this->pendingTlsWatchers[$clientId] = $watcher;
            }
        }
    }
    
    private function doTlsHandshake($clientSock) {
        $isSuccess = @stream_socket_enable_crypto($clientSock, TRUE, $this->cryptoType);
        
        if ($isSuccess) {
            $this->clearPendingTlsClient($clientSock);
            $this->onClient($clientSock);
            $result = TRUE;
        } elseif ($isSuccess === FALSE) {
            $this->failTlsConnection($clientSock);
            $result = TRUE;
        } else {
            $result = FALSE;
        }
        
        return $result;
    }
    
    private function failTlsConnection($clientSock) {
        $this->clearPendingTlsClient($clientSock);
        
        if (is_resource($clientSock)) {
            @fclose($clientSock);
        }
        if ($this->cachedClientCount-- === $this->maxConnections) {
            $this->resume();
        }
    }
    
    private function clearPendingTlsClient($clientSock) {
        $clientId = (int) $clientSock;
        if ($handshakeWatcher = $this->pendingTlsWatchers[$clientId]) {
            $this->reactor->cancel($handshakeWatcher);
        }
        unset($this->pendingTlsWatchers[$clientId]);
    }
    
    private function onClient($socket) {
        stream_set_blocking($socket, FALSE);
        
        $socketId = (int) $socket;
        
        $client = new Client;
        $client->id = $socketId;
        $client->socket = $socket;
        
        $rawServerName = stream_socket_get_name($socket, FALSE);
        list($client->serverAddress, $client->serverPort) = $this->parseSocketName($rawServerName);
        
        $rawClientName = stream_socket_get_name($socket, TRUE);
        list($client->clientAddress, $client->clientPort) = $this->parseSocketName($rawClientName);
        
        $client->isEncrypted = isset(stream_context_get_options($socket)['ssl']);
        
        $client->parser = $this->isExtHttpEnabled
            ? new PeclMessageParser(Parser::MODE_REQUEST)
            : new MessageParser(Parser::MODE_REQUEST);
        
        $onHeaders = function($requestArr) use ($client) {
            $this->afterRequestHeaders($client, $requestArr);
        };
        
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
        
        if ($this->verbosity & self::LOUD) {
            echo 'CON: ', $client->clientAddress, ':', $client->clientPort, ' | (', $this->cachedClientCount, ')', PHP_EOL;
        }
    }
    
    private function parseSocketName($name) {
        $portStartPos = strrpos($name, ':');
        $addr = substr($name, 0, $portStartPos);
        $port = substr($name, $portStartPos + 1);
        
        return [$addr, $port];
    }
    
    private function doClientRead(Client $client) {
        $data = @fread($client->socket, $this->socketReadGranularity);
        
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
        if (!$requestInitStruct = $this->initializeRequest($client, $requestArr)) {
            // initializeRequest() returns NULL if the server has already responded to the request
            return;
        }
        
        list($requestId, $asgiEnv, $host) = $requestInitStruct;
        
        if ($this->requireBodyLength && empty($asgiEnv['CONTENT_LENGTH'])) {
            $asgiResponse = [Status::LENGTH_REQUIRED, Reason::HTTP_411, ['Connection: close'], NULL];
            return $this->setResponse($requestId, $asgiResponse);
        }
        
        $needs100Continue = isset($asgiEnv['HTTP_EXPECT']) && !strcasecmp($asgiEnv['HTTP_EXPECT'], '100-continue');
        
        $client->preBodyRequest = [$requestId, $asgiEnv, $host, $needs100Continue];
        $client->requests[$requestId] = $asgiEnv;
        $client->requestHeaderTraces[$requestId] = $requestArr['trace'];
        
        $this->invokeOnHeadersMods($host, $requestId);
        
        if ($needs100Continue && empty($client->responses[$requestId])) {
            $client->responses[$requestId] = [Status::CONTINUE_100, Reason::HTTP_100, [], NULL];
        }
        
        if (isset($client->responses[$requestId])) {
            $this->writePipelinedResponses($client);
        }
    }
    
    /**
     * @TODO Clean up this mess. Right now a NULL return value signifies to afterRequestHeaders() 
     * and onRequest() that the server has already assigned a response so that they stop after this
     * return. This needs to be cleaned up (in addition to the two calling methods) for better
     * readability.
     */
    private function initializeRequest(Client $client, array $requestArr) {
        $requestId = ++$this->lastRequestId;
        $this->requestIdClientMap[$requestId] = $client;
        
        if ($this->verbosity & self::LOUD) {
            echo 'REQ: ', $client->clientAddress, ':', $client->clientPort, ' | ', rtrim(array_filter(explode("\n", $requestArr['trace']))[0]), PHP_EOL;
        }
        
        $client->requestCount += !isset($client->requests[$requestId]);
        
        $method = $requestArr['method'] = $this->normalizeMethodCase
            ? strtoupper($requestArr['method'])
            : $requestArr['method'];
        
        if ($host = $this->selectRequestHost($requestArr, $client->isEncrypted)) {
            $asgiEnv = $this->generateAsgiEnv($client, $host, $requestArr);
            
            $client->requests[$requestId] = $asgiEnv;
            $client->requestHeaderTraces[$requestId] = $requestArr['trace'];
            
            if (!isset($this->allowedMethods[$method])) {
                return $this->setResponse($requestId, $this->generateMethodNotAllowedResponse());
            } elseif ($method === 'TRACE') {
                return $this->setResponse($requestId, $this->generateTraceResponse($requestArr['trace']));
            } elseif ($method === 'OPTIONS' && $requestArr['uri'] === '*') {
                return $this->setResponse($requestId, $this->generateOptionsResponse());
            }
            
            return [$requestId, $asgiEnv, $host];
            
        } else {
            $client->requests[$requestId] = $this->generateAsgiEnv($client, $this->selectDefaultHost(), $requestArr);
            $client->requestHeaderTraces[$requestId] = $requestArr['trace'];
            
            return $this->setResponse($requestId, $this->generateInvalidHostNameResponse());
        }
    }
    
    private function generateInvalidHostNameResponse() {
        $status = Status::BAD_REQUEST;
        $reason = Reason::HTTP_400 . ': Invalid Host';
        $body = '<html><body><h1>' . $status . ' ' . $reason . '</h1></body></html>';
        $headers = [
            'Content-Type: text/html; charset=iso-8859-1',
            'Content-Length: ' . strlen($body),
            'Connection: close'
        ];
        
        return [$status, $reason, $headers, $body];
    }
    
    private function generateMethodNotAllowedResponse() {
        return [
            $status = Status::METHOD_NOT_ALLOWED,
            $reason = Reason::HTTP_405,
            $headers = ['Allow: ' . implode(',', array_keys($this->allowedMethods))],
            $body = NULL
        ];
    }
    
    private function generateTraceResponse($body) {
        $headers = [
            'Content-Length: ' . strlen($body), 
            'Content-Type: text/plain; charset=utf-8'
        ];
        
        return [
            $status = Status::OK,
            $reason = Reason::HTTP_200,
            $headers,
            $body
        ];
    }
    
    private function generateOptionsResponse() {
        return [
            $status = Status::OK,
            $reason = Reason::HTTP_200,
            $headers = ['Allow: ' . implode(',', array_keys($this->allowedMethods))],
            $body = NULL
        ];
    }
    
    /**
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec5.html#sec5.2
     */
    private function selectRequestHost(array $requestArr, $isSocketEncrypted) {
        $protocol = $requestArr['protocol'];
        $requestUri = $requestArr['uri'];
        $headers = array_change_key_case($requestArr['headers'], CASE_UPPER);
        $hostHeader = empty($headers['HOST']) ? NULL : strtolower(current($headers['HOST']));
        
        if (0 === stripos($requestUri, 'http://') || stripos($requestUri, 'https://') === 0) {
            $host = $this->selectHostByAbsoluteUri($requestUri);
        } elseif ($hostHeader !== NULL || $protocol >= 1.1) {
            $host = $this->selectHostByHeader($hostHeader, $isSocketEncrypted);
        } else {
            $host = $this->selectDefaultHost();
        }
        
        return $host;
    }
    
    /**
     * @TODO Figure out how best to allow for forward proxy applications with absolute URIs
     */
    private function selectHostByAbsoluteUri($uri) {
        $urlParts = parse_url($uri);
        $port = empty($urlParts['port']) ? '80' : $urlParts['port'];
        $hostId = strtolower($urlParts['host']) . ':' . $port;
        
        return isset($this->hosts[$hostId])
            ? $this->hosts[$hostId]
            : NULL;
    }
    
    private function selectHostByHeader($hostHeader, $isSocketEncrypted) {
        $hostHeader = strtolower($hostHeader);
        
        if ($portStartPos = strrpos($hostHeader , ':')) {
            $port = substr($hostHeader, $portStartPos + 1);
        } else {
            $port = $isSocketEncrypted ? '443' : '80';
            $hostHeader .= ":{$port}";
        }
        
        $wildcardHost = "*:{$port}";
        
        if (isset($this->hosts[$hostHeader])) {
            $host = $this->hosts[$hostHeader];
        } elseif (isset($this->hosts[$wildcardHost])) {
            $host = $this->hosts[$wildcardHost];
        } else {
            $host = NULL;
        }
        
        return $host;
    }
    
    private function selectDefaultHost() {
        return $this->defaultHost ?: current($this->hosts);
    }
    
    private function generateAsgiEnv(Client $client, Host $host, array $requestArr) {
        $uri = $requestArr['uri'];
        if ($uri === '/' || $uri === '*') {
            $queryString = '';
        } else {
            $queryString = ($qPos = strpos($uri, '?')) ? substr($uri, $qPos + 1) : '';
        }
        
        $urlScheme = $client->isEncrypted ? 'https' : 'http';
        $serverName = $host->hasVhostName() ? $host->getName() : $client->serverAddress;
        
        $asgiEnv = [
            'ASGI_VERSION'      => '0.1',
            'ASGI_CAN_STREAM'   => TRUE,
            'ASGI_NON_BLOCKING' => TRUE,
            'ASGI_LAST_CHANCE'  => empty($requestArr['headersOnly']),
            'ASGI_ERROR'        => $this->errorStream,
            'ASGI_INPUT'        => $requestArr['body'],
            'ASGI_URL_SCHEME'   => $urlScheme,
            'AERYS_HOST_ID'     => $host->getId(),
            'SERVER_PORT'       => $client->serverPort,
            'SERVER_ADDR'       => $client->serverAddress,
            'SERVER_NAME'       => $serverName,
            'SERVER_PROTOCOL'   => (string) $requestArr['protocol'],
            'REMOTE_ADDR'       => $client->clientAddress,
            'REMOTE_PORT'       => (string) $client->clientPort,
            'REQUEST_METHOD'    => $requestArr['method'],
            'REQUEST_URI'       => $uri,
            'QUERY_STRING'      => $queryString
        ];
        
        if ($headers = $requestArr['headers']) {
            $headers = array_change_key_case($headers, CASE_UPPER);
        }
        
        if (!empty($headers['CONTENT-TYPE'])) {
            $asgiEnv['CONTENT_TYPE'] = $headers['CONTENT-TYPE'][0];
            unset($headers['CONTENT-TYPE']);
        }
        
        if (!empty($headers['CONTENT-LENGTH'])) {
            $asgiEnv['CONTENT_LENGTH'] = $headers['CONTENT-LENGTH'][0];
            unset($headers['CONTENT-LENGTH']);
        }
        
        foreach ($headers as $field => $value) {
            $field = 'HTTP_' . str_replace('-', '_', $field);
            $asgiEnv[$field] = isset($value[1]) ? implode(',', $value) : $value[0];
        }
        
        return $asgiEnv;
    }
    
    private function invokeOnHeadersMods(Host $host, $requestId) {
        foreach ($host->getOnHeadersMods() as $mod) {
            $mod->onHeaders($requestId);
        }
    }
    
    private function onRequest(Client $client, array $requestArr) {
        unset($this->keepAliveTimeouts[$client->id]);
        
        if ($client->preBodyRequest) {
            return $this->finalizePreBodyRequest($client, $requestArr);
        }
        
        if (!$requestInitStruct = $this->initializeRequest($client, $requestArr)) {
            // initializeRequest() returns NULL if the server has already responded to the request
            return;
        }
        
        list($requestId, $asgiEnv, $host) = $requestInitStruct;
        
        $this->invokeOnHeadersMods($host, $requestId);
        
        if (isset($client->requests[$requestId]) && !isset($client->responses[$requestId])) {
            // Mods may have altered the request environment, so reload it.
            $asgiEnv = $client->requests[$requestId];
            $this->invokeRequestHandler($requestId, $asgiEnv, $host->getHandler());
        }
    }
    
    private function finalizePreBodyRequest(Client $client, $requestArr) {
        list($requestId, $asgiEnv, $host, $needsNewRequestId) = $client->preBodyRequest;
        $client->preBodyRequest = NULL;
        
        if ($needsNewRequestId) {
            $requestId = ++$this->lastRequestId;
            $this->requestIdClientMap[$requestId] = $client;
        }
        
        if ($hasTrailer = !empty($asgiEnv['HTTP_TRAILER'])) {
            $asgiEnv = $this->generateAsgiEnv($client, $host, $requestArr);
        }
        
        if ($needsNewRequestId || $hasTrailer) {
            $client->requests[$requestId] = $asgiEnv;
        }
        
        $this->invokeRequestHandler($requestId, $asgiEnv, $host->getHandler());
    }
    
    private function invokeRequestHandler($requestId, array $asgiEnv, callable $handler) {
        try {
            if ($asgiResponse = $handler($asgiEnv, $requestId)) {
                $this->setResponse($requestId, $asgiResponse);
            }
        } catch (\Exception $e) {
            $this->setResponse($requestId, [Status::INTERNAL_SERVER_ERROR, Reason::HTTP_500, [], $e]);
        }
    }
    
    private function onParseError(Client $client, ParseException $e) {
        $requestId = $client->preBodyRequest
            ? $client->preBodyRequest[0]
            : $this->generateParseErrorEnvironment($client, $e);
        
        $asgiResponse = $this->generateAsgiResponseFromParseException($e);
        $this->setResponse($requestId, $asgiResponse);
    }
    
    private function generateParseErrorEnvironment(Client $client, ParseException $e) {
        $requestId = ++$this->lastRequestId;
        $this->requestIdClientMap[$requestId] = $client;
        $parsedMsgArr = $e->getParsedMsgArr();
        $client->requestHeaderTraces[$requestId] = $parsedMsgArr['trace'] ?: '?';
        
        $uri = $parsedMsgArr['uri'];
        
        if ((strpos($uri, 'http://') === 0 || strpos($uri, 'https://') === 0)) {
            $host = $this->selectHostByAbsoluteUri($uri) ?: $this->selectDefaultHost();
        } elseif ($parsedMsgArr['headers']
            && ($headers = array_change_key_case($parsedMsgArr['headers']))
            && isset($headers['HOST'])
        ) {
            $host = $this->selectHostByHeader($hostHeader) ?: $this->selectDefaultHost();
        } else {
            $host = $this->selectDefaultHost();
        }
        
        $asgiEnv = $this->generateAsgiEnv($client, $host, $e->getParsedMsgArr());
        $asgiEnv['SERVER_PROTOCOL'] = '1.0';
        $client->requests[$requestId] = $asgiEnv;
        
        return $requestId;
    }
    
    private function generateAsgiResponseFromParseException(ParseException $e) {
        $status = $e->getCode() ?: Status::BAD_REQUEST;
        $reason = $this->getReasonPhrase($status);
        $msg = $e->getMessage();
        $body = "<html><body><h1>{$status} {$reason}</h1><p>{$msg}</p></body></html>";
        $headers = [
            'Date: ' . date(self::HTTP_DATE),
            'Content-Type: text/html; charset=utf-8',
            'Content-Length: ' . strlen($body),
            'Connection: close'
        ];
        
        return [$status, $reason, $headers, $body];
    }
    
    private function getReasonPhrase($statusCode) {
        $reasonConst = 'Aerys\\Reason::HTTP_' . $statusCode;
        return defined($reasonConst) ? constant($reasonConst) : '';
    }
    
    function setResponse($requestId, array $asgiResponse) {
        if ($this->isInsideAfterResponseModLoop) {
            throw new \LogicException(
                'Cannot modify response isInside AfterResponseMod loop'
            );
        }
        
        if (!isset($this->requestIdClientMap[$requestId])) {
            return;
        }
        
        $client = $this->requestIdClientMap[$requestId];
        $asgiEnv = $client->requests[$requestId];
        $client->responses[$requestId] = $asgiResponse;
        
        if (!$this->isInsideBeforeResponseModLoop) {
            $host = $this->hosts[$asgiEnv['AERYS_HOST_ID']];
            $this->invokeBeforeResponseMods($host, $requestId);
            
            // We need to make another isset check in case a BeforeResponseMod has exported the socket
            // @TODO clean this up to get rid of the ugly nested if statement
            if (isset($this->requestIdClientMap[$requestId])) {
                $this->writePipelinedResponses($client);
            }
        }
    }
    
    private function invokeBeforeResponseMods(Host $host, $requestId) {
        $this->isInsideBeforeResponseModLoop = TRUE;
        foreach ($host->getBeforeResponseMods() as $mod) {
            $mod->beforeResponse($requestId);
        }
        $this->isInsideBeforeResponseModLoop = FALSE;
    }
    
    private function writePipelinedResponses(Client $client) {
        foreach ($client->requests as $requestId => $asgiEnv) {
            if (isset($client->pipeline[$requestId])) {
                continue;
            } elseif (isset($client->responses[$requestId])) {
                $this->queuePipelineResponseWriter($client, $requestId, $asgiEnv);
            } else {
                break;
            }
        }
        
        reset($client->requests);
        
        $this->doClientWrite($client);
    }
    
    private function queuePipelineResponseWriter(Client $client, $requestId, array $asgiEnv) {
        $asgiResponse = $client->responses[$requestId];
        $asgiResponse = $this->doResponseNormalization($client, $asgiEnv, $asgiResponse);
        $client->responses[$requestId] = $asgiResponse;
        list($status, $reason, $headers, $body) = $asgiResponse;
        $protocol = $asgiEnv['SERVER_PROTOCOL'];
        $rawHeaders = "HTTP/$protocol $status";
        if ($reason || $reason === '0') {
            $rawHeaders .= " {$reason}";
        }
        $rawHeaders .= "\r\n{$headers}\r\n\r\n";
        
        $responseWriter = $this->writerFactory->make($client->socket, $rawHeaders, $body, $protocol);
        $client->pipeline[$requestId] = $responseWriter;
    }
    
    private function doResponseNormalization($client, $asgiEnv, $asgiResponse) {
        try {
            $asgiResponse = $this->normalizeResponseForSend($client, $asgiEnv, $asgiResponse);
        } catch (\UnexpectedValueException $e) {
            $status = 500;
            $reason = 'Internal Server Error';
            $body = $e->getMessage();
            $headers = 'Content-Length: ' . strlen($body) .
                "\r\nContent-Type: text/plain;charset=utf-8" .
                "\r\nConnection: close";
            
            $asgiResponse = [$status, $reason, $headers, $body];
        }
        
        return $asgiResponse;
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
    
    /**
     * @throws \UnexpectedValueException On bad ASGI response array
     */
    private function normalizeResponseForSend(Client $client, array $asgiEnv, array $asgiResponse) {
        list($status, $reason, $headers, $body) = $asgiResponse;
        
        $status = $this->filterResponseStatus($status);
        
        if ($this->autoReasonPhrase && !$reason) {
            $reason = $this->getReasonPhrase($status);
        }
        
        $headers = $this->stringifyResponseHeaders($headers);
        
        if ($status >= 200) {
            $isHttp11 = ($asgiEnv['SERVER_PROTOCOL'] === '1.1');
            list($headers, $close) = $this->normalizeConnectionHeader($headers, $asgiEnv, $client);
            list($headers, $close) = $this->normalizeEntityHeaders($headers, $body, $isHttp11, $close);
        } else {
            $close = FALSE;
        }
        
        if ($this->sendServerToken && (($serverTokenPos = stripos($headers, "\r\nServer:")) === FALSE)) {
            $headers .= "\r\nServer: " . self::SERVER_SOFTWARE;
        }
        
        if ($this->alwaysAddDateHeader && (stripos($headers, "\r\nDate:") === FALSE)) {
            $headers .= "\r\nDate: " . gmdate('D, d M Y H:i:s') . ' UTC';
        }
        
        // This MUST happen AFTER content-length/body normalization or headers won't be correct
        $requestMethod = $asgiEnv['REQUEST_METHOD'];
        if ($requestMethod === 'HEAD') {
            $body = NULL;
        }
        
        $headers = trim($headers);
        
        $client->closeAfterSend[] = $close;
        
        $asgiResponse = isset($asgiResponse[4])
            ? [$status, $reason, $headers, $body, $asgiResponse[4]]
            : [$status, $reason, $headers, $body];
        
        return $asgiResponse;
    }
    
    private function filterResponseStatus($status) {
        $status = (int) $status;
        
        if ($status < 100 || $status > 599) {
            throw new \UnexpectedValueException(
                'Invalid response status code'
            );
        }
        
        return $status;
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
    
    private function normalizeConnectionHeader($headers, $asgiEnv, Client $client) {
        if ($this->disableKeepAlive) {
            $valueToAssign = 'close';
        } elseif ($this->maxRequests > 0 && $client->requestCount >= $this->maxRequests) {
            $valueToAssign = 'close';
        } elseif (isset($asgiEnv['HTTP_CONNECTION'])) {
            $valueToAssign = stristr($asgiEnv['HTTP_CONNECTION'], 'keep-alive') ? 'keep-alive' : 'close';
        } elseif ($asgiEnv['SERVER_PROTOCOL'] !== '1.1') {
            $valueToAssign = 'close';
        } else {
            $valueToAssign = 'keep-alive';
        }
        
        $currentHeaderPos = stripos($headers, "\r\nConnection:");
        $newConnectionHeader = "\r\nConnection: {$valueToAssign}";
        
        if ($currentHeaderPos === FALSE) {
            $headers .= $newConnectionHeader;
        } else {
            $lineEndPos = strpos($headers, "\r\n", $currentHeaderPos + 2);
            $start = substr($headers, 0, $currentHeaderPos);
            $end = substr($headers, $lineEndPos);
            $headers = $start . $newConnectionHeader . $end;
        }
        
        $willClose = ($valueToAssign === 'close');
        
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
        $end = substr($headers, $lineEndPos);
        $headers = $start . "\r\nConnection: close" . $end;
        
        return $headers;
    }
    
    private function removeContentLengthAndTransferEncodingHeaders($headers) {
        $clPos = stripos($headers, "\r\nContent-Length:");
        $tePos = stripos($headers, "\r\nTransfer-Encoding:");
        
        if ($clPos !== FALSE) {
            $lineEndPos = strpos($headers, "\r\n", $clPos + 2);
            $start = substr($headers, 0, $clPos);
            $end = substr($headers, $lineEndPos);
            $headers = $start . $end;
        }
        
        if ($tePos !== FALSE) {
            $lineEndPos = strpos($headers, "\r\n", $tePos + 2);
            $start = substr($headers, 0, $tePos);
            $end = substr($headers, $lineEndPos);
            $headers = $start . $end;
        }
        
        return $headers;
    }
    
    private function afterResponse(Client $client, $requestId) {
        $asgiEnv = $client->requests[$requestId];
        $asgiResponse = $client->responses[$requestId];
        list($status, $reason) = $asgiResponse;
        if ($this->verbosity & self::LOUD) {
            echo 'RES: ', $client->clientAddress, ':', $client->clientPort, ' | HTTP/',  $asgiEnv['SERVER_PROTOCOL'], " {$status} {$reason}\n";
        }
        
        $host = $this->hosts[$asgiEnv['AERYS_HOST_ID']];
        $this->invokeAfterResponseMods($host, $requestId);
        
        if ($status === Status::SWITCHING_PROTOCOLS) {
            $this->clearClientReferences($client);
            $upgradeCallback = $asgiResponse[4];
            $upgradeCallback($client->socket, $asgiEnv);
        } elseif (array_shift($client->closeAfterSend)) {
            $this->closeClient($client);
        } else {
            $this->dequeueClientPipelineRequest($client, $requestId);
        }
    }
    
    private function invokeAfterResponseMods(Host $host, $requestId) {
        $this->isInsideAfterResponseModLoop = TRUE;
        foreach ($host->getAfterResponseMods() as $mod) {
            $mod->afterResponse($requestId);
        }
        $this->isInsideAfterResponseModLoop = FALSE;
    }
    
    private function clearClientReferences(Client $client) {
        if ($client->requests) {
            foreach (array_keys($client->requests) as $requestId) {
                unset($this->requestIdClientMap[$requestId]);
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
        
        if ($this->cachedClientCount-- === $this->maxConnections) {
            $this->resume();
        }
    }
    
    private function closeClient(Client $client) {
        $this->clearClientReferences($client);
        $this->doSocketClose($client->socket);
        if ($this->verbosity & self::LOUD) {
            echo 'DIS: ', $client->clientAddress, ':', $client->clientPort, ' | (', $this->cachedClientCount, ')', PHP_EOL;
        }
    }
    
    private function doSocketClose($socket) {
        if ($this->socketSoLingerZero) {
            $this->closeSocketWithSoLingerZero($socket);
        } elseif (is_resource($socket)) {
            stream_socket_shutdown($socket, STREAM_SHUT_WR); 
            stream_set_blocking($socket, FALSE);
            while(@fgets($socket) !== FALSE);
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
    
    private function dequeueClientPipelineRequest($client, $requestId) {
        unset(
            $client->pipeline[$requestId],
            $client->requests[$requestId],
            $client->responses[$requestId],
            $client->requestHeaderTraces[$requestId],
            $this->requestIdClientMap[$requestId]
        );
        
        // Disable active onWritable stream watchers if the pipeline is no longer write-ready
        if (!current($client->pipeline)) {
            $this->reactor->disable($client->writeWatcher);
            $this->renewKeepAliveTimeout($client->id);
        }
    }
    
    function setRequest($requestId, array $asgiEnv) {
        if (isset($this->requestIdClientMap[$requestId])) {
            $client = $this->requestIdClientMap[$requestId];
        } else {
            throw new \DomainException(
                "Unknown request ID: {$requestId}"
            );
        }
        
        $client->requests[$requestId] = $asgiEnv;
    }
    
    function getRequest($requestId) {
        if (isset($this->requestIdClientMap[$requestId])) {
            $client = $this->requestIdClientMap[$requestId];
        } else {
            throw new \DomainException(
                "Unknown request ID: {$requestId}"
            );
        }
        
        return $client->requests[$requestId];
    }
    
    function getResponse($requestId) {
        if (isset($this->requestIdClientMap[$requestId])) {
            $client = $this->requestIdClientMap[$requestId];
        } else {
            throw new \DomainException(
                "Unknown request ID: {$requestId}"
            );
        }
        
        return $client->responses[$requestId];
    }
    
    function getTrace($requestId) {
        if (isset($this->requestIdClientMap[$requestId])) {
            $client = $this->requestIdClientMap[$requestId];
        } else {
            throw new \DomainException(
                "Unknown request ID: {$requestId}"
            );
        }
        
        return $client->requestHeaderTraces[$requestId];
    }
    
    /**
     * Export a socket from the HTTP server
     * 
     * Note that exporting a socket removes all references to its existince from the HTTP server.
     * If any HTTP requests remain outstanding in the client's pipeline the will go unanswered. As
     * far as the HTTP server is concerned the socket no longer exists. The only exception to this
     * rule is the Server::$cachedClientCount variable: _IT WILL NOT BE DECREMENTED_. This is a
     * safety measure. When code that exports sockets is ready to close those sockets IT MUST
     * pass the relevant socket resource back to `Server::closeExportedSocket()` or the server
     * will eventually max out it's allowed client slots. This requirement also allows exported
     * sockets to take advantage of HTTP server options such as socketSoLingerZero.
     * 
     * @param int $requestId
     * @throws \DomainException On unknown request ID
     * @return resource Returns the socket stream associated with the specified request ID
     */
    function exportSocket($requestId) {
        if (isset($this->requestIdClientMap[$requestId])) {
            $client = $this->requestIdClientMap[$requestId];
        } else {
            throw new \DomainException(
                "Unknown request ID: {$requestId}"
            );
        }
        
        $this->clearClientReferences($client);
        $socketId = (int) $client->socket;
        $this->exportedSocketIds[$socketId] = TRUE;
        $this->cachedClientCount++; // It was decremented when references were cleared, take it back.
        
        return $client->socket;
    }
    
    /**
     * Retrieve information about the socket associated with a given request ID
     * 
     * @param $requestId
     * @return array Returns an array of information about the socket underlying the request ID
     */
    function querySocket($requestId) {
        if (isset($this->requestIdClientMap[$requestId])) {
            $client = $this->requestIdClientMap[$requestId];
        } else {
            throw new \DomainException(
                "Unknown request ID: {$requestId}"
            );
        }
        
        return [
            'clientAddress' => $client->clientAddress,
            'clientPort' => $client->clientPort,
            'serverAddress' => $client->serverAddress,
            'serverPort' => $client->serverPort,
            'isEncrypted' => $client->isEncrypted
        ];
    }
    
    /**
     * Must be called to close/unload the socket once exporting code is finished with the resource
     * 
     * @param resource $socket An exported socket resource
     * @return void
     */
    function closeExportedSocket($socket) {
        $socketId = (int) $socket;
        if (isset($this->exportedSocketIds[$socketId])) {
            unset($this->exportedSocketIds[$socketId]);
            $this->cachedClientCount--;
        }
    }
    
    /**
     * @TODO Add documentation
     */
    function getErrorStream() {
        return $this->errorStream;
    }
    
    /**
     * Set multiple server options at one time
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
        $option = strtolower($option);
        
        switch ($option) {
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
            case 'logerrorsto':
                $this->setLogErrorsTo($value); break;
            case 'sendservertoken':
                $this->setSendServerToken($value); break;
            case 'normalizemethodcase':
                $this->setNormalizeMethodCase($value); break;
            case 'alwaysadddateHeader':
                $this->setAlwaysAddDateHeader($value); break;
            case 'requirebodylength':
                $this->setRequireBodyLength($value); break;
            case 'socketsolingerzero':
                $this->setSocketSoLingerZero($value); break;
            case 'allowedmethods':
                $this->setAllowedMethods($value); break;
            case 'defaulthost':
                $this->setDefaultHost($value); break;
            case 'verbosity':
                $this->setVerbosity($value); break;
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
    
    private function setLogErrorsTo($filePath) {
        $this->logErrorsTo = $filePath;
    }
    
    private function setSendServerToken($boolFlag) {
        $this->sendServerToken = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }
    
    private function setNormalizeMethodCase($boolFlag) {
        $this->normalizeMethodCase = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }
    
    private function setAlwaysAddDateHeader($boolFlag) {
        $this->alwaysAddDateHeader = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
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
    
    private function setAllowedMethods(array $methods) {
        $this->allowedMethods = array_map(function() { return 1; }, array_flip($methods));
        $this->allowedMethods['GET'] = 1;
        $this->allowedMethods['HEAD'] = 1;
    }
    
    private function setDefaultHost($hostId) {
        if (isset($this->hosts[$hostId])) {
            $this->defaultHost = $this->hosts[$hostId];
        } elseif ($hostId !== NULL) {
            throw new \DomainException(
                "Invalid default host; unknown host ID: {$hostId}"
            );
        }
    }
    
    private function setVerbosity($verbosity) {
        if (in_array($verbosity, [self::SILENT, self::QUIET, self::LOUD], $strict = TRUE)) {
            $this->verbosity = (int) $verbosity;
        } else {
            throw new \DomainException(
                "Invalid verbosity level: {$verbosity}"
            );
        }
    }
    
    /**
     * Retrieve a server option value
     * 
     * @param string $option The (case-insensitive) server option key
     * @throws \DomainException On unknown option
     * @return mixed The value of the requested server option
     */
    function getOption($option) {
        $option = strtolower($option);
        
        switch ($option) {
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
            case 'logerrorsto':
                return $this->logErrorsTo; break;
            case 'sendservertoken':
                return $this->sendServerToken; break;
            case 'normalizemethodcase':
                return $this->normalizeMethodCase; break;
            case 'alwaysadddateHeader':
                return $this->alwaysAddDateHeader; break;
            case 'requirebodylength':
                return $this->requireBodyLength; break;
            case 'socketsolingerzero':
                return $this->socketSoLingerZero; break;
            case 'allowedmethods':
                return array_keys($this->allowedMethods); break;
            case 'defaulthost':
                return $this->defaultHost ? $this->defaultHost->getId() : NULL; break;
            case 'verbosity':
                return $this->verbosity; break;
            default:
                throw new \DomainException(
                    "Unknown server option: {$option}"
                );
        }
    }
    
}
