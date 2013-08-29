<?php

namespace Aerys;

use Alert\Reactor,
    Alert\Forkable,
    Aerys\Parsing\Parser,
    Aerys\Parsing\MessageParser,
    Aerys\Parsing\PeclMessageParser,
    Aerys\Parsing\ParseException,
    Aerys\Writing\WriterFactory,
    Aerys\Writing\ResourceException;

class Server implements Forkable {

    const SERVER_SOFTWARE = 'Aerys/0.1.0-devel';

    private $isExtHttpEnabled;
    private $isExtSocketsEnabled;

    private $state;
    private static $UNBOUND = 0;
    private static $PAUSED = 1;
    private static $LISTENING = 2;

    private $reactor;
    private $hosts = [];
    private $servers = [];
    private $acceptWatchers = [];
    private $pendingTlsWatchers = [];
    private $clients = [];
    private $requestIdMap = [];
    private $exportedSocketIdMap = [];
    private $lastRequestId = 0;
    private $cachedClientCount = 0;

    private $now;
    private $httpDateNow;
    private $httpDateFormat = 'D, d M Y H:i:s';
    private $keepAliveWatcher;
    private $keepAliveTimeouts = [];
    private $keepAliveWatcherInterval = 1;

    private $isBeforeResponse = FALSE;
    private $isAfterResponse = FALSE;

    private $errorStream = STDERR;
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
    private $requireBodyLength = TRUE;
    private $maxHeaderBytes = 8192;
    private $maxBodyBytes = 2097152;
    private $allowedMethods = ['GET', 'HEAD', 'OPTIONS', 'TRACE', 'PUT', 'POST', 'PATCH', 'DELETE'];
    private $cryptoType = STREAM_CRYPTO_METHOD_TLS_SERVER; // @TODO Add option setter
    private $readGranularity = 262144; // @TODO Add option setter
    private $defaultHost;

    function __construct(Reactor $reactor, WriterFactory $wf = NULL) {
        $this->state = self::$UNBOUND;
        $this->reactor = $reactor;
        $this->writerFactory = $wf ?: new WriterFactory;

        $this->isExtHttpEnabled = extension_loaded('http');
        $this->isExtSocketsEnabled = extension_loaded('sockets');

        $this->now = time();
        $this->httpDateNow = gmdate($this->httpDateFormat, $this->now) . ' UTC';
        $this->keepAliveWatcher = $this->reactor->repeat(function() {
            $this->timeoutKeepAlives();
        }, $this->keepAliveWatcherInterval);
        
        register_shutdown_function(function() { $this->onShutdown(); });
    }
    
    /**
     * Register a server Host on which to listen
     *
     * @param Host $host A host definition for responding to client requests
     * @return int Returns the new number of hosts registered with the server
     * @TODO Track which addresses have TLS enabled and prevent conflicts in multi-host environments
     */
    function addHost(Host $host) {
        $hostId = $host->getId();
        $this->hosts[$hostId] = $host;

        return count($this->hosts);
    }

    /**
     * Bind all host sockets but don't listen yet
     *
     * @throws \LogicException If no hosts have yet been added to the server
     * @throws \RuntimeException On server socket bind failure
     * @return array Returns an array of all bound server addresses
     */
    function bind() {
        if (empty($this->hosts)) {
            throw new \LogicException(
                'Cannot bind server sockets: no hosts exist!'
            );
        }

        foreach ($this->hosts as $host) {
            $ip = ($host->getAddress() === '*') ? '0.0.0.0' : $host->getAddress();
            $bindAddress = sprintf('tcp://%s:%d', $ip, $host->getPort());

            if (!isset($this->servers[$bindAddress])) {
                $context = $host->getContext();
                $this->bindServer($bindAddress, $context);
            }
        }

        reset($this->hosts);
        $this->state = self::$LISTENING;
        
        return array_keys($this->servers);
    }

    private function bindServer($bindAddress, $context) {
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        
        if ($server = @stream_socket_server($bindAddress, $errNo, $errStr, $flags, $context)) {
            $this->servers[$bindAddress] = $server;
            stream_set_blocking($server, FALSE);
        } else {
            throw new \RuntimeException(
                sprintf('Failed binding server to %s: [Err# %s] %s', $bindAddress, $errNo, $errStr)
            );
        }
    }

    /**
     * Stop listening and unbind all server sockets
     * 
     * @return void
     */
    function unbind() {
        foreach ($this->servers as $server) {
            if (is_resource($server)) {
                @fclose($server);
            }
        }
        
        $this->servers = [];
        
        foreach ($this->acceptWatchers as $watcherId) {
            $this->reactor->cancel($watcherId);
        }
        
        $this->acceptWatchers = [];
    }

    /**
     * Listen for new connections on bound host sockets
     *
     * @throws \LogicException If no hosts added
     * @throws \RuntimeException On bind failure
     * @return void
     */
    function listen() {
        if ($this->state === self::$UNBOUND) {
            $this->bind();
        }

        foreach ($this->servers as $bindAddress => $server) {
            if (isset($this->acceptWatchers[$bindAddress])) {
                $this->reactor->enable($this->acceptWatchers[$bindAddress]);
            } else {
                $isEncrypted = isset(stream_context_get_options($server)['ssl']);
                $onAcceptable = $isEncrypted
                    ? function($watcherId, $server) { $this->acceptTls($server); }
                    : function($watcherId, $server) { $this->accept($server); };
                $acceptWatcher = $this->reactor->onReadable($server, $onAcceptable);
                $this->acceptWatchers[$bindAddress] = $acceptWatcher;
            }
        }

        $this->state = self::$LISTENING;
    }

    private function timeoutKeepAlives() {
        $now = time();
        $this->now = $now;
        $this->httpDateNow = gmdate('D, d M Y H:i:s', $now) . ' UTC';
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

    private function accept($server) {
        while ($client = @stream_socket_accept($server, $timeout = 0)) {
            stream_set_blocking($client, FALSE);
            $this->cachedClientCount++;
            $this->onClient($client);
            if ($this->maxConnections > 0 && $this->cachedClientCount >= $this->maxConnections) {
                $this->pauseNewClientAcceptance();
                break;
            }
        }
    }

    private function pauseNewClientAcceptance() {
        if ($this->state === self::$LISTENING) {
            foreach ($this->acceptWatchers as $watcherId) {
                $this->reactor->disable($watcherId);
            }
            $this->state = self::$PAUSED;
        }
    }

    private function acceptTls($server) {
        while ($client = @stream_socket_accept($server, $timeout = 0)) {
            stream_set_blocking($client, FALSE);

            $cryptoEnabler = function() use ($client) { $this->doTlsHandshake($client); };
            $this->pendingTlsWatchers[(int) $client] = $this->reactor->onReadable($client, $cryptoEnabler);

            $this->cachedClientCount++;
            if ($this->maxConnections > 0 && $this->cachedClientCount >= $this->maxConnections) {
                $this->pauseNewClientAcceptance();
                break;
            }
        }
    }

    private function doTlsHandshake($client) {
        $isTlsHandshakeSuccessful = @stream_socket_enable_crypto($client, TRUE, $this->cryptoType);

        if ($isTlsHandshakeSuccessful) {
            $this->clearPendingTlsClient($client);
            $this->onClient($client);
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

        if ($this->state === self::$PAUSED
            && $this->maxConnections > 0
            && $this->cachedClientCount <= $this->maxConnections
        ) {
            $this->listen();
        }
    }

    private function clearPendingTlsClient($client) {
        $clientId = (int) $client;

        if ($cryptoWatcher = $this->pendingTlsWatchers[$clientId]) {
            $this->reactor->cancel($cryptoWatcher);
        }

        unset($this->pendingTlsWatchers[$clientId]);
    }

    /**
     * Register a Mod for all hosts matching the specified $hostId
     *
     * Host IDs can match in multiple different ways:
     *
     * - *              Applies mod to ALL currently registered hosts
     * - *:80           Applies mod to ALL currently registered hosts listening on port 80
     * - 127.0.0.1:*    Applies mod to ALL currently registered hosts listening on any port at 127.0.0.1
     * - mysite.com:*   Applies mod to all hosts on any port with the name mysite.com
     * - mysite.com:80  Applies mod to ONLY mysite.com on port 80
     *
     * @param string $hostId The Host ID matching string
     * @param \Aerys\Mods\Mod $mod The mod instance to register
     * @param array $priorityMap Optionally specify mod invocation priority values
     * @throws \DomainException If no registered hosts match the specified $hostId
     * @return int Returns the number of hosts to which the mod was successfully applied
     */
    function registerMod($hostId, $mod, array $priorityMap = []) {
        $matchCount = 0;
        foreach ($this->hosts as $host) {
            if ($host->matchesId($hostId)) {
                $matchCount++;
                $host->registerMod($mod, $priorityMap);
            }
        }

        return $matchCount;
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
        $this->requestIdMap[$requestId] = $client;

        $client->requestCount += !isset($client->requests[$requestId]);

        $protocol = $requestArr['protocol'];
        $method = $requestArr['method'] = $this->normalizeMethodCase
            ? strtoupper($requestArr['method'])
            : $requestArr['method'];

        $uriInfo = $this->getRequestUriInfo($requestArr['uri']);
        $headers = array_change_key_case($requestArr['headers'], CASE_UPPER);

        $requestArr['uriInfo'] = $uriInfo;
        $requestArr['headers'] = $headers;

        $hostHeader = empty($headers['HOST']) ? NULL : strtolower($headers['HOST'][0]);

        $host = $this->selectRequestHost($uriInfo, $hostHeader, $client->isEncrypted, $protocol);

        if ($host) {
            $asgiEnv = $this->generateAsgiEnv($client, $host, $requestArr);

            $client->requests[$requestId] = $asgiEnv;
            $client->requestHeaderTraces[$requestId] = $requestArr['trace'];

            if (!in_array($method, $this->allowedMethods)) {
                $asgiResponse = $this->generateMethodNotAllowedResponse();
                return $this->setResponse($requestId, $asgiResponse);
            } elseif ($method === 'TRACE' && empty($headers['MAX-FORWARDS'][0])) {
                $asgiResponse = $this->generateTraceResponse($requestArr['trace']);
                return $this->setResponse($requestId, $asgiResponse);
            } elseif ($method === 'OPTIONS' && $requestArr['uri'] === '*') {
                $asgiResponse = $this->generateOptionsResponse();
                return $this->setResponse($requestId, $asgiResponse);
            }

            return [$requestId, $asgiEnv, $host];

        } else {
            $host = $this->selectDefaultHost();
            $client->requests[$requestId] = $this->generateAsgiEnv($client, $host, $requestArr);
            $client->requestHeaderTraces[$requestId] = $requestArr['trace'];

            return $this->setResponse($requestId, $this->generateInvalidHostNameResponse());
        }
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

    private function getRequestUriInfo($requestUri) {
        if (stripos($requestUri, 'http://') === 0 || stripos($requestUri, 'https://') === 0) {
            $parts = parse_url($requestUri);
            return [
                'isAbsolute' => TRUE,
                'query' => $parts['query'],
                'path' => $parts['path'],
                'port' => $parts['port']
            ];
        }

        if ($qPos = strpos($requestUri, '?')) {
            $query = substr($requestUri, $qPos + 1);
            $path = substr($requestUri, 0, $qPos);
        } else {
            $query = '';
            $path = $requestUri;
        }

        return [
            'isAbsolute' => FALSE,
            'query' => $query,
            'path' => $path,
            'port' => NULL
        ];
    }

    /**
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec5.html#sec5.2
     */
    private function selectRequestHost(array $uriInfo, $hostHeader, $isEncrypted, $protocol) {
        if ($uriInfo['isAbsolute']) {
            $host = $this->selectHostByAbsoluteUri($uriInfo, $isEncrypted);
        } elseif ($hostHeader !== NULL || $protocol >= 1.1) {
            $host = $this->selectHostByHeader($hostHeader, $isEncrypted);
        } else {
            $host = $this->selectDefaultHost();
        }

        return $host;
    }

    /**
     * @TODO return default host for forward proxy use-cases
     */
    private function selectHostByAbsoluteUri(array $uriInfo, $isEncrypted) {
        $port = $uriInfo['port'] ?: ($isEncrypted ? 443 : 80);
        $hostId = $uriInfo['host'] . ':' . $port;
        $host = isset($this->hosts[$hostId]) ? $this->hosts[$hostId] : NULL;
    }

    private function selectHostByHeader($hostHeader, $isEncrypted) {
        if ($portStartPos = strrpos($hostHeader, ']')) {
            $ipComparison = substr($hostHeader, 0, $portStartPos + 1);
            $port = substr($hostHeader, $portStartPos + 2);
            $port = ($port === FALSE) ? ($isEncrypted ? '443' : '80') : $port;
        } elseif ($portStartPos = strrpos($hostHeader, ':')) {
            $ipComparison = substr($hostHeader, 0, $portStartPos);
            $port = substr($hostHeader, $portStartPos + 1);
        } else {
            $port = $isEncrypted ? '443' : '80';
            $ipComparison = $hostHeader;
            $hostHeader .= ":{$port}";
        }

        $wildcardHost = "*:{$port}";
        $ipv6WildcardHost = "[::]:{$port}";

        if (isset($this->hosts[$hostHeader])) {
            $host = $this->hosts[$hostHeader];
        } elseif (isset($this->hosts[$wildcardHost])) {
            $host = $this->hosts[$wildcardHost];
        } elseif (isset($this->hosts[$ipv6WildcardHost])) {
            $host = $this->hosts[$ipv6WildcardHost];
        } elseif (!($host = $this->attemptIpHostSelection($hostHeader, $ipComparison))) {
            $host = NULL;
        }

        return $host;
    }

    private function attemptIpHostSelection($hostHeader, $ipComparison) {
        if (count($this->hosts) !== 1) {
            $host = NULL;
        } elseif (!filter_var($ipComparison, FILTER_VALIDATE_IP)) {
            $host = NULL;
        } elseif (!(($host = current($this->hosts))
            && ($host->getAddress() === $ipComparison || $host->hasWildcardAddress())
        )) {
            $host = NULL;
        }

        return $host;
    }

    private function selectDefaultHost() {
        return $this->defaultHost ?: current($this->hosts);
    }

    private function generateAsgiEnv(Client $client, Host $host, array $requestArr) {
        $uriInfo = $requestArr['uriInfo'];
        $uriScheme = $client->isEncrypted ? 'https' : 'http';
        $serverName = $host->hasName() ? $host->getName() : $client->serverAddress;

        $asgiEnv = [
            'ASGI_VERSION'      => '0.1',
            'ASGI_CAN_STREAM'   => TRUE,
            'ASGI_NON_BLOCKING' => TRUE,
            'ASGI_LAST_CHANCE'  => empty($requestArr['headersOnly']),
            'ASGI_ERROR'        => $this->errorStream,
            'ASGI_INPUT'        => $requestArr['body'],
            'ASGI_URL_SCHEME'   => $uriScheme,
            'AERYS_HOST_ID'     => $host->getId(),
            'SERVER_PORT'       => $client->serverPort,
            'SERVER_ADDR'       => $client->serverAddress,
            'SERVER_NAME'       => $serverName,
            'SERVER_PROTOCOL'   => (string) $requestArr['protocol'],
            'REMOTE_ADDR'       => $client->clientAddress,
            'REMOTE_PORT'       => (string) $client->clientPort,
            'REQUEST_METHOD'    => $requestArr['method'],
            'REQUEST_URI'       => $requestArr['uri'],
            'REQUEST_URI_PATH'  => $uriInfo['path'],
            'QUERY_STRING'      => $uriInfo['query']
        ];

        $headers = $requestArr['headers'];

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

    private function finalizePreBodyRequest(Client $client, array $requestArr) {
        list($requestId, $asgiEnv, $host, $needsNewRequestId) = $client->preBodyRequest;
        $client->preBodyRequest = NULL;

        if ($needsNewRequestId) {
            $requestId = ++$this->lastRequestId;
            $this->requestIdMap[$requestId] = $client;
        }

        if (!empty($asgiEnv['HTTP_TRAILER'])) {
            $asgiEnv = $this->updateRequestEnvFromTrailers($asgiEnv, $requestArr);
        }

        $asgiEnv['ASGI_LAST_CHANCE'] = TRUE;

        $client->requests[$requestId] = $asgiEnv;

        $this->invokeRequestHandler($requestId, $asgiEnv, $host->getHandler());
    }

    private function updateRequestEnvFromTrailers(array $asgiEnv, array $finalRequestArr) {
        $newHeaders = array_change_key_case($finalRequestArr['headers'], CASE_UPPER);

        // The Host header is ignored in trailers to prevent unsanitized values from bypassing the
        // safety check when headers are originally received. The other values are expressly
        // disallowed by RFC 2616 Section 14.40.
        unset(
            $newHeaders['TRANSFER-ENCODING'],
            $newHeaders['CONTENT-LENGTH'],
            $newHeaders['TRAILER'],
            $newHeaders['HOST']
        );

        $trailerExpectation = $asgiEnv['HTTP_TRAILER'];
        $allowedTrailers = array_map('strtoupper', array_map('trim', explode(',', $trailerExpectation)));

        foreach ($allowedTrailers as $trailerField) {
            if (isset($newHeaders[$trailerField])) {
                $field = 'HTTP_' . str_replace('-', '_', $trailerField);
                $value = $newHeaders[$trailerField];
                $asgiEnv[$field] = isset($value[1]) ? implode(',', $value) : $value[0];
            }
        }

        return $asgiEnv;
    }

    private function invokeRequestHandler($requestId, array $asgiEnv, callable $handler) {
        try {
            if ($asgiResponse = $handler($asgiEnv, $requestId)) {
                $this->setResponse($requestId, $asgiResponse);
            }
        } catch (\Exception $e) {
            $asgiResponse = [500, 'Internal Server Error', [], $e->__toString()];
            $this->setResponse($requestId, $asgiResponse);
        }
    }

    private function onParseError(Client $client, ParseException $e) {
        $requestId = $client->preBodyRequest
            ? $client->preBodyRequest[0]
            : $this->generateParseErrorEnv($client, $e);

        $asgiResponse = $this->generateParseErrorResponse($e);

        $this->setResponse($requestId, $asgiResponse);
    }

    private function generateParseErrorEnv(Client $client, ParseException $e) {
        $requestId = ++$this->lastRequestId;
        $this->requestIdMap[$requestId] = $client;

        $requestArr = $e->getParsedMsgArr();
        $requestArr['protocol'] = $protocol = '1.0';
        $requestArr['headersOnly'] = FALSE;

        if ($headers = $requestArr['headers']) {
            $uriInfo = $this->getRequestUriInfo($requestArr['uri']);
            $requestArr['uriInfo'] = $uriInfo;
            $headers = array_change_key_case($requestArr['headers'], CASE_UPPER);
            $hostHeader = empty($headers['HOST']) ? NULL : $headers['HOST'][0];
            $host = $this->selectRequestHost($uriInfo, $hostHeader, $client->isEncrypted, $protocol);
        } else {
            $host = $this->selectDefaultHost();
            $requestArr['uriInfo'] = [
                'path' => '?',
                'query' => '?'
            ];
        }

        $client->requestHeaderTraces[$requestId] = $requestArr['trace'];
        $client->requests[$requestId] = $this->generateAsgiEnv($client, $host, $requestArr);

        return $requestId;
    }

    private function generateParseErrorResponse(ParseException $e) {
        $status = $e->getCode() ?: Status::BAD_REQUEST;
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
        $reasonConst = 'Aerys\\Reason::HTTP_' . $statusCode;
        return defined($reasonConst) ? constant($reasonConst) : '';
    }

    function setResponse($requestId, array $asgiResponse) {
        if (!isset($this->requestIdMap[$requestId])) {
            return;
        }

        $client = $this->requestIdMap[$requestId];
        $asgiEnv = $client->requests[$requestId];
        $client->responses[$requestId] = $asgiResponse;

        if (!$this->isBeforeResponse) {
            $host = $this->hosts[$asgiEnv['AERYS_HOST_ID']];
            $this->invokeBeforeResponseMods($host, $requestId);

            // We need to make another isset check in case a BeforeResponseMod has exported the socket
            // @TODO clean this up to get rid of the ugly nested if statement
            if (isset($this->requestIdMap[$requestId])) {
                $this->writePipelinedResponses($client);
            }
        }
    }

    private function invokeBeforeResponseMods(Host $host, $requestId) {
        $this->isBeforeResponse = TRUE;
        foreach ($host->getBeforeResponseMods() as $mod) {
            $mod->beforeResponse($requestId);
        }
        $this->isBeforeResponse = FALSE;
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

        if (stripos($headers, "\r\nDate:") === FALSE) {
            $headers .= "\r\nDate: " . $this->httpDateNow;
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
        $currentHeaderPos = stripos($headers, "\r\nConnection:");
        
        if ($currentHeaderPos !== FALSE) {
            $lineEndPos = strpos($headers, "\r\n", $currentHeaderPos + 2);
            $line = substr($headers, $currentHeaderPos, $lineEndPos - $currentHeaderPos);
            $willClose = stristr($line, 'close');
            
            return [$headers, $willClose];
        }
        
        if ($this->disableKeepAlive) {
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
        $asgiEnv = $client->requests[$requestId];
        $asgiResponse = $client->responses[$requestId];
        list($status, $reason) = $asgiResponse;

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
        $this->isAfterResponse = TRUE;
        foreach ($host->getAfterResponseMods() as $mod) {
            $mod->afterResponse($requestId);
        }
        $this->isAfterResponse = FALSE;
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

        if ($this->state === self::$PAUSED
            && $this->maxConnections > 0
            && $this->cachedClientCount <= $this->maxConnections
        ) {
            $this->listen();
        }
    }

    private function closeClient(Client $client) {
        $this->clearClientReferences($client);
        $this->doSocketClose($client->socket);
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
            $this->requestIdMap[$requestId]
        );

        // Disable active onWritable stream watchers if the pipeline is no longer write-ready
        if (!current($client->pipeline)) {
            $this->reactor->disable($client->writeWatcher);
            $this->renewKeepAliveTimeout($client->id);
        }
    }

    function setRequest($requestId, array $asgiEnv) {
        if (isset($this->requestIdMap[$requestId])) {
            $client = $this->requestIdMap[$requestId];
        } else {
            throw new \DomainException(
                "Unknown request ID: {$requestId}"
            );
        }

        $client->requests[$requestId] = $asgiEnv;
    }

    function getRequest($requestId) {
        if (isset($this->requestIdMap[$requestId])) {
            $client = $this->requestIdMap[$requestId];
        } else {
            throw new \DomainException(
                "Unknown request ID: {$requestId}"
            );
        }

        return $client->requests[$requestId];
    }

    function getResponse($requestId) {
        if (isset($this->requestIdMap[$requestId])) {
            $client = $this->requestIdMap[$requestId];
        } else {
            throw new \DomainException(
                "Unknown request ID: {$requestId}"
            );
        }

        return $client->responses[$requestId];
    }

    function getTrace($requestId) {
        if (isset($this->requestIdMap[$requestId])) {
            $client = $this->requestIdMap[$requestId];
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
        if (isset($this->requestIdMap[$requestId])) {
            $client = $this->requestIdMap[$requestId];
        } else {
            throw new \DomainException(
                "Unknown request ID: {$requestId}"
            );
        }

        $this->clearClientReferences($client);
        $socketId = (int) $client->socket;
        $this->exportedSocketIdMap[$socketId] = TRUE;
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
        if (isset($this->requestIdMap[$requestId])) {
            $client = $this->requestIdMap[$requestId];
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
        if (isset($this->exportedSocketIdMap[$socketId])) {
            unset($this->exportedSocketIdMap[$socketId]);
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

    private function setErrorStream($pathOrResource) {
        if (is_resource($pathOrResource)) {
            $this->errorStream = $pathOrResource;
        } elseif (is_string($pathOrResource)) {
            $this->errorStream = fopen($pathOrResource, 'a+');
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
        if (isset($this->hosts[$hostId])) {
            $this->defaultHost = $this->hosts[$hostId];
        } elseif ($hostId !== NULL) {
            throw new \DomainException(
                "Invalid default host; unknown host ID: {$hostId}"
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
            case 'allowedmethods':
                return $this->allowedMethods; break;
            case 'defaulthost':
                return $this->defaultHost ? $this->defaultHost->getId() : NULL; break;
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
            'errorStream'           => $this->errorStream,
            'sendServerToken'       => $this->sendServerToken,
            'normalizeMethodCase'   => $this->normalizeMethodCase,
            'requireBodyLength'     => $this->requireBodyLength,
            'socketSoLingerZero'    => $this->socketSoLingerZero,
            'allowedMethods'        => $this->allowedMethods,
            'defaultHost'           => ($this->defaultHost ? $this->defaultHost->getId() : NULL)
        ];
    }
    
    private function onShutdown() {
        $fatals = [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING];
        $errorArr = error_get_last();
        if ($errorArr && in_array($errorArr['type'], $fatals)) {
            extract($errorArr);
            $error = sprintf('%s in %s on line %d', $message, $file, $line);
            $this->doErrorShutdown($error);
        }
    }
    
    function doErrorShutdown($error) {
        $this->unbind();
        
        $asgiResponse = [
            $status = 500,
            $reason = 'Internal Server Error',
            $headers = ['Connection: close'],
            $body = sprintf(
                '<html><body><h1>500 Internal Server Error</h1><p>%s</p></body></html>',
                $error
            )
        ];
        
        foreach ($this->clients as $client) {
            stream_socket_shutdown($client->socket, STREAM_SHUT_RD);
            
            $outstandingRequestIds = array_keys(array_diff_key($client->requests, $client->responses));
            
            foreach ($outstandingRequestIds as $requestId) {
                $this->setResponse($requestId, $asgiResponse);
            }
        }
    }

    /**
     * Convenience method for running the composed event reactor
     * 
     * @return void
     */
    function run() {
        $this->reactor->run();
    }
    
    /**
     * Invoke before forking a new process or risk reactor stream IO watcher corruption
     * 
     * @return void
     */
    function beforeFork() {
        if ($this->reactor instanceof Forkable) {
            $this->reactor->beforeFork();
        }
    }
    
    /**
     * Invoke in child processes after forking or risk reactor stream IO watcher corruption
     * 
     * @return void
     */
    function afterFork() {
        if ($this->reactor instanceof Forkable) {
            $this->reactor->afterFork();
        }
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
    }
}
