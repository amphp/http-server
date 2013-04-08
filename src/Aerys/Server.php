<?php

namespace Aerys;

use Amp\Reactor,
    Amp\Server\TcpServer,
    Aerys\Io\RequestParser,
    Aerys\Io\MessageWriter,
    Aerys\Io\TempEntityWriter,
    Aerys\Io\ParseException,
    Aerys\Io\ResourceException,
    Aerys\Io\BodyWriterFactory;

class Server {
    
    const SERVER_SOFTWARE = 'Aerys/0.0.1';
    const HTTP_DATE = 'D, d M Y H:i:s T';
    const WILDCARD = '*';
    const UNKNOWN = '?';
    const PROTOCOL_V10 = '1.0';
    const PROTOCOL_V11 = '1.1';
    
    private $reactor;
    private $tcpServers;
    private $hosts = [];
    private $bodyWriterFactory;
    private $errorStream = STDERR;
    
    private $clients = [];
    private $clientsRequiringWrite = [];
    private $readSubscriptions = [];
    private $requestIdClientMap = [];
    private $closeInProgress = [];
    private $cachedClientCount = 0;
    private $lastRequestId = 0;
    
    private $isListening = FALSE;
    private $acceptEnabled = TRUE;
    private $insideBeforeResponseModLoop = FALSE;
    private $insideAfterResponseModLoop = FALSE;
    
    private $onRequestMods = [];
    private $beforeResponseMods = [];
    private $afterResponseMods = [];
    
    private $maxConnections = 0;
    private $maxRequestsPerSession = 250;
    private $idleConnectionTimeout = 30;
    private $autoWriteInterval = 0.025; // @TODO add option setter
    private $gracefulCloseInterval = 1; // @TODO add option setter
    private $maxStartLineSize = 2048;
    private $maxHeadersSize = 8192;
    private $maxEntityBodySize = 2097152;
    private $tempEntityDir = NULL;
    private $defaultContentType = 'text/html';
    private $defaultCharset = 'utf-8';
    private $autoReasonPhrase = TRUE;
    private $handleBeforeBody = FALSE;
    private $errorLogFile = NULL;
    private $sendServerToken = FALSE;
    private $disableKeepAlive = FALSE;
    private $defaultHost;
    private $dontCombineHeaders = ['SET-COOKIE' => 1];
    private $normalizeMethodCase = TRUE;
    private $allowedMethods = [
        Method::GET     => 1,
        Method::HEAD    => 1,
        Method::OPTIONS => 1,
        Method::TRACE   => 1,
        Method::PUT     => 1,
        Method::POST    => 1,
        Method::DELETE  => 1
    ];
    
    function __construct(Reactor $reactor, BodyWriterFactory $bwf = NULL) {
        $this->reactor = $reactor;
        $this->tcpServers = new \SplObjectStorage;
        $this->bodyWriterFactory = $bwf ?: new BodyWriterFactory;
        $this->tempEntityDir = sys_get_temp_dir();
    }
    
    function addTcpServer(TcpServer $tcpServer) {
        $this->tcpServers->attach($tcpServer);
    }
    
    function addHost(Host $host) {
        $hostId = $host->getId();
        $this->hosts[$hostId] = $host;
    }
    
    function listen() {
        if (!$this->isListening) {
            $this->isListening = TRUE;
            
            if ($this->errorLogFile) {
                $this->errorStream = fopen($this->errorLogFile, 'ab+');
            }
            
            foreach ($this->tcpServers as $tcpServer) {
                $tcpServer->listen(function($clientSock, $peerName, $serverName) {
                    $this->accept($clientSock, $peerName, $serverName);
                });
                
                $listeningOn = $tcpServer->getAddress() . ':' . $tcpServer->getPort();
                echo 'Server listening on ', $listeningOn, PHP_EOL;
            }
            /*
            $diagnostics = $this->reactor->repeat(function() {
                echo time(), ' (', $this->cachedClientCount, ")\n";
            }, $delay = 1);
            */
            $this->reactor->repeat(function() { $this->write(); }, $this->autoWriteInterval);
            $this->reactor->repeat(function() { $this->gracefulClose(); }, $this->gracefulCloseInterval);
            
            $this->reactor->run();
        }
    }
    
    function stop() {
        if (!$this->isListening) {
            return;
        }
        
        foreach ($this->servers as $server) {
            $server->stop();
        }
        
        $this->reactor->stop();
        
        if ($this->errorStream !== STDERR) {
            fclose($this->errorStream);
        }
    }
    
    private function disableNewClients() {
        foreach ($this->servers as $server) {
            $server->disable();
        }
        $this->acceptEnabled = FALSE;
    }
    
    private function enableNewClients() {
        foreach ($this->servers as $server) {
            $server->enable();
        }
        $this->acceptEnabled = TRUE;
    }
    
    private function write() {
        foreach ($this->clientsRequiringWrite as $client) {
            try {
                $pipelineWriteResult = $client->write();
                
                if ($pipelineWriteResult < 0) {
                    return;
                }
                
                $this->afterResponse($client);
                
                if (!$pipelineWriteResult) {
                    $clientId = $client->getId();
                    unset($this->clientsRequiringWrite[$clientId]);
                }
            } catch (ResourceException $e) {
                $this->sever($client);
            }
        }
    }
    
    private function accept($clientSock, $peerName, $serverName) {
        $parser = new RequestParser($clientSock);
        $writer = new MessageWriter($clientSock, $this->bodyWriterFactory);
        $client = new ClientSession($clientSock, $peerName, $serverName, $parser, $writer);
        
        $parser->setMaxStartLineBytes($this->maxStartLineSize);
        $parser->setMaxHeaderBytes($this->maxHeadersSize);
        $parser->setMaxBodyBytes($this->maxEntityBodySize);
        
        $parser->onHeaders(function(array $parsedRequest) use ($client) {
            $this->onHeaders($client, $parsedRequest);
        });
        
        $clientId = $client->getId();
        $this->clients[$clientId] = $client;
        
        $this->readSubscriptions[$clientId] = $this->reactor->onReadable($clientSock,
            function ($clientSock, $trigger) use ($client) {
                $this->onReadable($trigger, $client);
            },
            $this->idleConnectionTimeout
        );
        
        ++$this->cachedClientCount;
        
        if ($this->maxConnections && $this->cachedClientCount >= $this->maxConnections) {
            $this->disableNewClients();
        }
        
        if ($this->clientsRequiringWrite) {
            $this->write();
        }
    }
    
    private function onReadable($triggeredBy, ClientSession $client) {
        if ($triggeredBy == Reactor::TIMEOUT) {
            return $this->handleReadTimeout($client);
        }
        
        try {
            if ($parsedRequest = $client->read()) {
                $this->onRequest($client, $parsedRequest);
            }
        } catch (ResourceException $e) {
            $this->sever($client);
        } catch (ParseException $e) {
            switch ($e->getCode()) {
                case RequestParser::E_START_LINE_TOO_LARGE:
                    $status = Status::REQUEST_URI_TOO_LONG;
                    break;
                case RequestParser::E_HEADERS_TOO_LARGE:
                    $status = Status::REQUEST_HEADER_FIELDS_TOO_LARGE;
                    break;
                case RequestParser::E_ENTITY_TOO_LARGE:
                    $status = Status::REQUEST_ENTITY_TOO_LARGE;
                    break;
                case RequestParser::E_PROTOCOL_NOT_SUPPORTED:
                    $status = Status::HTTP_VERSION_NOT_SUPPORTED;
                    break;
                default:
                    $status = Status::BAD_REQUEST;
            }
            
            $this->handleRequestParseError($client, $status);
        }
        
        if ($this->clientsRequiringWrite) {
            $this->write();
        }
    }
    
    private function handleReadTimeout(ClientSession $client) {
        if ($client->hasUnfinishedRead()) {
            $this->sever($client);
        } elseif ($client->isEmpty()) {
            $this->close($client);
        }
    }
    
    private function handleRequestParseError(ClientSession $client, $status) {
        $requestId = ++$this->lastRequestId;
        $this->requestIdClientMap[$requestId] = $client;
        
        // Generate a stand-in parsed request since there was a problem with the real one
        $parsedRequest = [
            'method'   => self::UNKNOWN,
            'uri'      => self::UNKNOWN,
            'protocol' => self::PROTOCOL_V10,
            'headers'  => []
        ];
        
        // Generate a placeholder $asgiEnv from our stand-in parsed request
        $asgiEnv = $this->generateAsgiEnv($client, '?', $parsedRequest);
        $client->setRequest($requestId, $asgiEnv);
        
        $reason = $this->getReasonPhrase($status);
        $heading = $status . ' ' . $reason;
        $body = '<html><body><h1>'.$heading.'</h1></body></html>';
        $headers = [
            'Date' => date(self::HTTP_DATE),
            'Content-Type' => 'text/html; charset=iso-8859-1',
            'Content-Length' => strlen($body),
            'Connection' => 'close'
        ];
        
        $this->setResponse($requestId, [$status, $reason, $headers, $body]);
    }
    
    private function onHeaders(ClientSession $client, array $parsedRequest) {
        if (!$requestStruct = $this->initializeNewRequest($client, $parsedRequest)) {
            return;
        }
        
        list($requestId, $asgiEnv, $host) = $requestStruct;
        
        $tempEntityPath = tempnam($this->tempEntityDir, 'aerys');
        $tempEntityWriter = new TempEntityWriter($tempEntityPath);
        $client->setTempEntityWriter($tempEntityWriter);
        $this->tempEntityWriters[$requestId] = $tempEntityWriter;
        
        $asgiEnv['ASGI_INPUT'] = $tempEntityWriter->getResource();
        $asgiEnv['ASGI_LAST_CHANCE'] = FALSE;
        
        $needs100Continue = (isset($asgiEnv['HTTP_EXPECT'])
            && !strcasecmp($asgiEnv['HTTP_EXPECT'], '100-continue')
        );
        
        $client->addPreBodyRequest($requestId, $asgiEnv, $host, $needs100Continue);
        
        $this->invokeOnRequestMods($host->getId(), $requestId);
        
        if ($client->hasResponse($requestId)) {
            $client->incrementRequestCount();
        } elseif ($this->handleBeforeBody
            && !$this->invokeRequestHandler($requestId, $asgiEnv, $host->getHandler())
            && $needs100Continue
        ) {
            $this->setResponse($requestId, [Status::CONTINUE_100, Reason::HTTP_100, [], NULL]);
        } elseif (!$this->handleBeforeBody && $needs100Continue) {
            $this->setResponse($requestId, [Status::CONTINUE_100, Reason::HTTP_100, [], NULL]);
        }
    }
    
    private function initializeNewRequest(ClientSession $client, array $parsedRequest) {
        $requestId = ++$this->lastRequestId;
        $this->requestIdClientMap[$requestId] = $client;
        
        $response = NULL;
        
        if ($this->normalizeMethodCase) {
            $parsedRequest['method'] = strtoupper($parsedRequest['method']);
        }
        
        $method = $parsedRequest['method'];
        
        if (!isset($this->allowedMethods[$method])) {
            $headers = ['Allow' => implode(',', array_keys($this->allowedMethods))];
            $response = [Status::METHOD_NOT_ALLOWED, Reason::HTTP_405, $headers, NULL];
            
        } elseif ($method == Method::TRACE) {
            $headers = [
                'Content-Length' => strlen($parsedRequest['trace']), 
                'Content-Type' => 'text/plain; charset=iso-8859-1'
            ];
            $response = [Status::OK, Reason::HTTP_200, $headers, $parsedRequest['trace']];
            
        } elseif ($method == Method::OPTIONS && $parsedRequest['uri'] == self::WILDCARD) {
            $headers = ['Allow' => implode(',', array_keys($this->allowedMethods))];
            $response = [Status::OK, Reason::HTTP_200, $headers, NULL];
        }
        
        if ($host = $this->selectRequestHost($parsedRequest)) {
            $asgiEnv = $this->generateAsgiEnv($client, $host->getName(), $parsedRequest);
        } else {
            $asgiEnv = $this->generateAsgiEnv($client, self::UNKNOWN, $parsedRequest);
            
            $status = Status::BAD_REQUEST;
            $reason = Reason::HTTP_400 . ': Invalid Host';
            $body = '<html><body><h1>' . $status . ' ' . $reason . '</h1></body></html>';
            $headers = [
                'Content-Type' => 'text/html; charset=iso-8859-1',
                'Content-Length' => strlen($body)
            ];
            $response = [$status, $reason, $headers, $body];
        }
        
        $client->setRequest($requestId, $asgiEnv);
        
        if ($response) {
            $this->setResponse($requestId, $response);
            return NULL;
        } else {
            return [$requestId, $asgiEnv, $host];
        }
    }
    
    /**
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec5.html#sec5.2
     * 
     * 1. If Request-URI is an absoluteURI, the host is part of the Request-URI. Any Host header field
     *    value in the request MUST be ignored.
     * 2. If the Request-URI is not an absoluteURI, and the request includes a Host header field, the
     *    host is determined by the Host header field value.
     * 3. If the host as determined by rule 1 or 2 is not a valid host on the server, the response MUST
     *    be a 400 (Bad Request) error message.
     * 
     * Recipients of an HTTP/1.0 request that lacks a Host header field MAY attempt to use heuristics 
     * (e.g., examination of the URI path for something unique to a particular host) in order to 
     * determine what exact resource is being requested.
     */
    private function selectRequestHost(array $parsedRequest) {
        $protocol = $parsedRequest['protocol'];
        $requestUri = $parsedRequest['uri'];
        $headers = $parsedRequest['headers'];
        $hostHeader = isset($headers['HOST'])
            ? strtolower($headers['HOST'])
            : NULL;
        
        if (0 === stripos($requestUri, 'http://') || stripos($requestUri, 'https://') === 0) {
            $host = $this->selectHostByAbsoluteUri($requestUri);
        } elseif ($hostHeader !== NULL || $protocol >= self::PROTOCOL_V11) {
            $host = $this->selectHostByHeader($hostHeader);
        } elseif ($protocol == self::PROTOCOL_V10) {
            $host = $this->defaultHost ?: current($this->hosts);
        }
        
        return $host;
    }
    
    /**
     * @TODO Figure out how best to allow for proxy servers here
     */
    private function selectHostByAbsoluteUri($uri) {
        $urlParts = parse_url($requestUri);
        $port = empty($urlParts['port']) ? '80' : $urlPorts['port'];
        $hostId = strtolower($urlParts['host']) . ':' . $port;
        
        return isset($this->hosts[$hostId])
            ? $this->hosts[$hostId]
            : NULL;
    }
    
    private function selectHostByHeader($hostHeader) {
        $hostHeader = strtolower($hostHeader);
        
        if ($portStartPos = strrpos($hostHeader , ':')) {
            $port = substr($hostHeader, $portStartPos + 1);
        } else {
            $port = '80';
            $hostHeader .= ':' . $port;
        }
        
        $wildcardHost = '*:' . $port;
        
        if (isset($this->hosts[$hostHeader])) {
            $host = $this->hosts[$hostHeader];
        } elseif (isset($this->hosts[$wildcardHost])) {
            $host = $this->hosts[$wildcardHost];
        } else {
            $host = NULL;
        }
        
        return $host;
    }
    
    private function invokeOnRequestMods($hostId, $requestId) {
        if (empty($this->onRequestMods[$hostId])) {
            return FALSE;
        }
        
        foreach ($this->onRequestMods[$hostId] as $mod) {
            try {
                $mod->onRequest($requestId);
            } catch (\Exception $e) {
                $asgiEnv = $this->getRequest($requestId);
                $serverName = $asgiEnv['SERVER_NAME'];
                $requestUri = $asgiEnv['REQUEST_URI'];
                
                $this->logUserlandError($e, $serverName, $requestUri);
            }
        }
        
        return TRUE;
    }
    
    private function invokeRequestHandler($requestId, array $asgiEnv, callable $handler) {
        try {
            if ($asgiResponse = $handler($asgiEnv, $requestId)) {
                $this->setResponse($requestId, $asgiResponse);
                return TRUE;
            } else {
                return FALSE;
            }
        } catch (\Exception $e) {
            $serverName = $asgiEnv['SERVER_NAME'];
            $requestUri = $asgiEnv['REQUEST_URI'];
            
            $this->logUserlandError($e, $serverName, $requestUri);
            $this->setResponse($requestId, [Status::INTERNAL_SERVER_ERROR, '', [], NULL]);
            
            return TRUE;
        }
    }
    
    private function getReasonPhrase($statusCode) {
        $reasonConst = 'Aerys\\Http\\Reason::HTTP_' . $statusCode;
        return defined($reasonConst) ? constant($reasonConst) : '';
    }
    
    private function generateAsgiEnv(ClientSession $client, $serverName, $parsedRequest) {
        $uri = $parsedRequest['uri'];
        $method = $this->normalizeMethodCase
            ? strtoupper($parsedRequest['method'])
            : $parsedRequest['method'];
        
        $headers = $parsedRequest['headers'];
        
        $queryString = '';
        $pathInfo = '';
        $scriptName = '';
        
        if ($uri == '/' || $uri == '*') {
            $queryString = '';
        } elseif ($uri != '?') {
            $uriParts = parse_url($uri);
            $queryString = isset($uriParts['query']) ? $uriParts['query'] : '';
            $decodedPath = rawurldecode($uriParts['path']);
            $pathParts = pathinfo($decodedPath);
        }
        
        $contentType = isset($headers['CONTENT-TYPE']) ? $headers['CONTENT-TYPE'] : '';
        $contentLength = isset($headers['CONTENT-LENGTH']) ? $headers['CONTENT-LENGTH'] : '';
        
        $scheme = isset(stream_context_get_options($client->getSocket())['ssl']) ? 'https' : 'http';
        
        $asgiEnv = [
            'SERVER_NAME'        => $serverName,
            'SERVER_PORT'        => $client->getServerPort(),
            'SERVER_PROTOCOL'    => $parsedRequest['protocol'],
            'REMOTE_ADDR'        => $client->getAddress(),
            'REMOTE_PORT'        => $client->getPort(),
            'REQUEST_METHOD'     => $method,
            'REQUEST_URI'        => $uri,
            'QUERY_STRING'       => $queryString,
            'CONTENT_TYPE'       => $contentType,
            'CONTENT_LENGTH'     => $contentLength,
            'ASGI_VERSION'       => 0.1,
            'ASGI_URL_SCHEME'    => $scheme,
            'ASGI_INPUT'         => NULL,
            'ASGI_ERROR'         => $this->errorStream,
            'ASGI_CAN_STREAM'    => TRUE,
            'ASGI_NON_BLOCKING'  => TRUE,
            'ASGI_LAST_CHANCE'   => TRUE
        ];
        
        foreach ($headers as $field => $value) {
            $field = strtoupper($field);
            if (!isset($this->dontCombineHeaders[$field])) {
                $value = ($value === (array) $value) ? implode(',', $value) : $value;
            }
            
            $field = 'HTTP_' . str_replace('-',  '_', $field);
            $asgiEnv[$field] = $value;
        }
        
        return $asgiEnv;
    }
    
    private function onRequest(ClientSession $client, array $parsedRequest) {
        if ($hasPreBodyRequest = $client->hasPreBodyRequest()) {
            list($requestId, $asgiEnv, $host, $needsNewRequestId) = $client->shiftPreBodyRequest();
            $asgiEnv['ASGI_LAST_CHANCE'] = TRUE;
            rewind($asgiEnv['ASGI_INPUT']);
        } elseif ($requestStruct = $this->initializeNewRequest($client, $parsedRequest)) {
            list($requestId, $asgiEnv, $host) = $requestStruct;
        } else {
            return;
        }
        
        if ($hasPreBodyRequest && $needsNewRequestId) {
            $requestId = ++$this->lastRequestId;
            $this->requestIdClientMap[$requestId] = $client;
        }
        
        $client->incrementRequestCount();
        
        // Headers may have changed in the presence of trailers, so regenerate the request environment
        $hasTrailerHeader = !empty($asgiEnv['HTTP_TRAILER']);
        if ($hasPreBodyRequest && $hasTrailerHeader) {
            $asgiEnv = $this->generateAsgiEnv($client, $host->getName(), $parsedRequest);
        }
        
        $client->setRequest($requestId, $asgiEnv);
        
        if (!$hasPreBodyRequest || $hasTrailerHeader) {
            $this->invokeOnRequestMods($host->getId(), $requestId);
        }
        
        $this->invokeRequestHandler($requestId, $asgiEnv, $host->getHandler());
    }
    
    private function afterResponse(ClientSession $client) {
        list($requestId, $asgiEnv, $asgiResponse) = $client->front();
        
        $hostId = $asgiEnv['SERVER_NAME'] . ':' . $client->getServerPort();
        $this->invokeAfterResponseMods($hostId, $requestId);
        
        if ($asgiResponse[0] == Status::SWITCHING_PROTOCOLS) {
            $upgradeCallback = $asgiResponse[4];
            $clientSock = $this->export($client);
            
            try {
                $upgradeCallback($clientSock, $asgiEnv);
            } catch (\Exception $e) {
                $serverName = $asgiEnv['SERVER_NAME'];
                $requestUri = $asgiEnv['REQUEST_URI'];
                
                $this->logUserlandError($e, $serverName, $requestUri);
            }
        } elseif ($this->shouldCloseAfterResponse($asgiEnv, $asgiResponse)) {
            $this->close($client);
        } else {
            $client->shift();
            unset(
                $this->requestIdClientMap[$requestId],
                $this->tempEntityWriters[$requestId]
            );
        }
    }
    
    private function shouldCloseAfterResponse(array $asgiEnv, array $asgiResponse) {
        $headers = $asgiResponse[2];
        
        if (isset($headers['CONNECTION']) && !strcasecmp('close', $headers['CONNECTION'])) {
            return TRUE;
        } elseif (isset($asgiEnv['HTTP_CONNECTION']) && !strcasecmp('close', $asgiEnv['HTTP_CONNECTION'])) {
            return TRUE;
        } elseif ($asgiEnv['SERVER_PROTOCOL'] == self::PROTOCOL_V10 && !isset($asgiEnv['HTTP_CONNECTION'])) {
            return TRUE;
        } else {
            return FALSE;
        }
    }
    
    private function invokeAfterResponseMods($hostId, $requestId) {
        if (!isset($this->afterResponseMods[$hostId])) {
            return;
        }
        
        $this->insideAfterResponseModLoop = TRUE;
        
        foreach ($this->afterResponseMods[$hostId] as $mod) {
            try {
                $mod->afterResponse($requestId);
            } catch (\Exception $e) {
                $serverName = $asgiEnv['SERVER_NAME'];
                $requestUri = $asgiEnv['REQUEST_URI'];
                
                $this->logUserlandError($e, $serverName, $requestUri);
            }
        }
        
        $this->insideAfterResponseModLoop = FALSE;
    }
    
    private function sever(ClientSession $client) {
        $clientSock = $this->export($client);
        
        // socket extension can't import stream if it has crypto enabled
        @stream_socket_enable_crypto($clientSock, FALSE);
        $rawSock = socket_import_stream($clientSock);
        
        socket_set_block($rawSock);
        socket_set_option($rawSock, SOL_SOCKET, SO_LINGER, [
            'l_onoff' => 1,
            'l_linger' => 0
        ]);
        
        socket_close($rawSock);
    }
    
    private function close(ClientSession $client) {
        $clientSock = $this->export($client);
        
        // socket extension can't import stream if it has crypto enabled
        @stream_socket_enable_crypto($clientSock, FALSE);
        $rawSock = socket_import_stream($clientSock);
        
        if (@socket_shutdown($rawSock, 1)) {
            socket_set_block($rawSock);
            $closeId = $client->getId();
            $this->closeInProgress[$closeId] = $rawSock;
        }
    }
    
    private function gracefulClose() {
        foreach ($this->closeInProgress as $closeId => $rawSock) {
            if (!@socket_recv($rawSock, $buffer, 8192, MSG_DONTWAIT)) {
                socket_close($rawSock);
                unset($this->closeInProgress[$closeId]);
            }
        }
    }
    
    private function export(ClientSession $client) {
        if ($requestIds = $client->getRequestIds()) {
            foreach ($requestIds as $requestId) {
                unset(
                    $this->requestIdClientMap[$requestId],
                    $this->tempEntityWriters[$requestId]
                );
            }
        }
        
        $clientId = $client->getId();
        
        $readSubscription = $this->readSubscriptions[$clientId];
        $readSubscription->cancel();
        
        unset(
            $this->clients[$clientId],
            $this->clientsRequiringWrite[$clientId],
            $this->readSubscriptions[$clientId]
        );
        
        --$this->cachedClientCount;
        
        if (!$this->acceptEnabled && $this->cachedClientCount < $this->maxConnections) {
            $this->enableNewClients();
        }
        
        return $client->getSocket();
    }
    
    function setResponse($requestId, array $asgiResponse) {
        if (!isset($this->requestIdClientMap[$requestId])) {
            return;
        } elseif ($this->insideAfterResponseModLoop) {
            throw new \LogicException(
                'Cannot modify response; message already sent'
            );
        }
        
        $client = $this->requestIdClientMap[$requestId];
        
        $asgiEnv = $client->getRequest($requestId);
        $asgiResponse = $this->normalizeResponse($asgiEnv, $asgiResponse);
        
        $is100Continue = ($asgiResponse[0] == 100);
        
        if ($this->disableKeepAlive || (
            !$is100Continue
            && $this->maxRequestsPerSession
            && $client->getRequestCount() >= $this->maxRequestsPerSession
        )) {
            $asgiResponse[2]['CONNECTION'] = 'close';
        }
        
        $client->setResponse($requestId, $asgiResponse);
        
        if ($this->insideBeforeResponseModLoop) {
            return;
        }
        
        $hostId = $asgiEnv['SERVER_NAME'] . ':' . $client->getServerPort();
        
        // Reload the response array in case it was altered by beforeResponse mods ...
        if ($this->invokeBeforeResponseMods($hostId, $requestId)) {
            $asgiResponse = $client->getResponse($requestId);
        }
        
        if ($shouldUpgrade = ($asgiResponse[0] == 101)) {
            $asgiResponse = $this->prepForProtocolUpgrade($asgiResponse);
            $client->setResponse($requestId, $asgiResponse);
        }
        
        $clientId = $client->getId();
        $queuedResponseCount = $client->enqueueResponsesForWrite();
        $pipelineWriteResult = $client->write();
        
        if ($pipelineWriteResult < 0) {
            $this->clientsRequiringWrite[$clientId] = $client;
        } elseif ($pipelineWriteResult === 0) {
            unset($this->clientsRequiringWrite[$clientId]);
            $this->afterResponse($client);
        } else {
            $this->afterResponse($client);
        }
    }
    
    private function normalizeResponse(array $asgiEnv, array $asgiResponse) {
        list($status, $reason, $headers, $body) = $asgiResponse;
        $exportCallback = isset($asgiResponse[4]) ? $asgiResponse[4] : NULL;
        
        if ($headers) {
            $headers = array_change_key_case($headers, CASE_UPPER);
        }
        
        if ($this->autoReasonPhrase && (string) $reason === '') {
            $reason = $this->getReasonPhrase($status);
        }
        
        if (isset($asgiEnv['HTTP_CONNECTION'])
            && empty($headers['CONNECTION'])
            && !strcasecmp($asgiEnv['HTTP_CONNECTION'], 'keep-alive')
        ) {
            $headers['CONNECTION'] = 'keep-alive';
        }
        
        if (!isset($headers['DATE'])) {
            $headers['DATE'] = date(self::HTTP_DATE);
        }
        
        if ($asgiEnv['REQUEST_METHOD'] == 'HEAD') {
            $body = NULL;
            $hasBody = FALSE;
        } else {
            $hasBody = ($body || $body === '0');
        }
        
        if ($hasBody && $body instanceof \Exception) {
            $body = (string) $body;
        }
        
        if ($hasBody && empty($headers['CONTENT-TYPE'])) {
            $headers['CONTENT-TYPE'] = $this->defaultContentType;
        }
        
        if ($hasBody
            && $this->defaultCharset
            && 0 === stripos($headers['CONTENT-TYPE'], 'text/')
            && !stristr($headers['CONTENT-TYPE'], 'charset=')
        ) {
            $headers['CONTENT-TYPE'] = $headers['CONTENT-TYPE'] . '; charset=' . $this->defaultCharset;
        }
        
        $hasContentLength = isset($headers['CONTENT-LENGTH']);
        
        if (!$hasBody && $status >= Status::OK && !$hasContentLength) {
            $headers['CONTENT-LENGTH'] = 0;
        }
        
        $isChunked = isset($headers['TRANSFER-ENCODING']) && !strcasecmp($headers['TRANSFER-ENCODING'], 'chunked');
        $isIterator = ($body instanceof \Iterator && !$body instanceof Http\MultiPartByteRangeBody);
        
        $protocol = ($asgiEnv['SERVER_PROTOCOL'] == self::UNKNOWN)
            ? self::PROTOCOL_V10
            : $asgiEnv['SERVER_PROTOCOL'];
        
        if ($hasBody && !$hasContentLength && is_string($body)) {
            $headers['CONTENT-LENGTH'] = strlen($body);
        } elseif ($hasBody && !$hasContentLength && is_resource($body)) {
            $currentPos = ftell($body);
            fseek($body, 0, SEEK_END);
            $headers['CONTENT-LENGTH'] = ftell($body) - $currentPos;
            fseek($body, $currentPos);
        } elseif ($hasBody && $protocol >= self::PROTOCOL_V11 && !$isChunked && $isIterator) {
            $headers['TRANSFER-ENCODING'] = 'chunked';
        } elseif ($hasBody && !$hasContentLength && $protocol < self::PROTOCOL_V11 && $isIterator) {
            $headers['CONNECTION'] = 'close';
        }
        
        if ($this->sendServerToken) {
            $headers['SERVER'] = self::SERVER_SOFTWARE;
        }
        
        return $exportCallback
            ? [$status, $reason, $headers, $body, $exportCallback]
            : [$status, $reason, $headers, $body];
    }
    
    private function invokeBeforeResponseMods($hostId, $requestId) {
        if (!isset($this->beforeResponseMods[$hostId])) {
            return 0;
        }
        
        $modInvocationCount = 0;
        
        $this->insideBeforeResponseModLoop = TRUE;
        
        foreach ($this->beforeResponseMods[$hostId] as $mod) {
            try {
                $mod->beforeResponse($requestId);
            } catch (\Exception $e) {
                $asgiEnv = $this->getRequest($requestId);
                $serverName = $asgiEnv['SERVER_NAME'];
                $requestUri = $asgiEnv['REQUEST_URI'];
                
                $this->logUserlandError($e, $serverName, $requestUri);
            }
            
            $modInvocationCount++;
        }
        
        $this->insideBeforeResponseModLoop = FALSE;
        
        return $modInvocationCount;
    }
    
    private function prepForProtocolUpgrade($asgiResponse) {
        if (isset($asgiResponse[4]) && is_callable($asgiResponse[4])) {
            return $asgiResponse;
        } else {
            $status = Status::INTERNAL_SERVER_ERROR;
            $reason = Reason::HTTP_500;
            $body = '<html><body><h1>' . $status . ' ' . $reason . '</h1></body></html>';
            $headers = [
                'Content-Type' => 'text/html; charset=iso-8859-1',
                'Content-Length' => strlen($body),
                'Connection' => 'close'
            ];
            
            return [$status, $reason, $headers, $body];
        }
    }
    
    function getResponse($requestId) {
        if (!isset($this->requestIdClientMap[$requestId])) {
            throw new \DomainException(
                'Request ID does not exist: ' . $requestId
            );
        }
        
        $client = $this->requestIdClientMap[$requestId];
        
        if ($client->hasResponse($requestId)) {
            return $client->getResponse($requestId);
        } else {
            throw new \DomainException(
                "Request ID $requestId does not exist or has no assigned response"
            );
        }
    }
    
    function getRequest($requestId) {
        if (!isset($this->requestIdClientMap[$requestId])) {
            throw new \DomainException(
                'Request ID does not exist: ' . $requestId
            );
        }
        
        $client = $this->requestIdClientMap[$requestId];
        
        if ($client->hasRequest($requestId)) {
            return $client->getRequest($requestId);
        } else {
            throw new \DomainException(
                'Request ID does not exist: ' . $requestId
            );
        }
    }
    
    function getErrorStream() {
        return $this->errorStream;
    }
    
    private function logUserlandError(\Exception $e, $host, $requestUri) {
        fwrite(
            $this->errorStream,
            '------------------------------------' . PHP_EOL .
            'Handler/Mod Exception' . PHP_EOL .
            'When: ' . date(self::HTTP_DATE) . PHP_EOL .
            'Host: ' . $host . PHP_EOL .
            'Request URI: ' . $requestUri .  PHP_EOL .
            $e . PHP_EOL .
            '------------------------------------' . PHP_EOL
        );
    }
    
    function setOption($option, $value) {
        $setter = 'set' . ucfirst($option);
        if (property_exists($this, $option) && method_exists($this, $setter)) {
            $this->$setter($value);
        } else {
            throw new \DomainException(
                'Invalid server option: ' . $option
            );
        }
    }
    
    private function setMaxConnections($maxConns) {
        $this->maxConnections = (int) $maxConns;
    }
    
    private function setMaxRequestsPerSession($maxRequests) {
        $this->maxRequestsPerSession = (int) $maxRequests;
    }
    
    private function setIdleConnectionTimeout($seconds) {
        $this->idleConnectionTimeout = (int) $seconds;
    }
    
    private function setMaxStartLineSize($bytes) {
        $this->maxStartLineSize = (int) $bytes;
    }
    
    private function setMaxHeadersSize($bytes) {
        $this->maxHeadersSize = (int) $bytes;
    }
    
    private function setMaxEntityBodySize($bytes) {
        $this->maxEntityBodySize = (int) $bytes;
    }
    
    private function setTempEntityDir($dir) {
        $this->tempEntityDir = $dir ?: sys_get_temp_dir();
    }
    
    private function setDefaultContentType($mimeType) {
        $this->defaultContentType = $mimeType;
    }
    
    private function setAutoReasonPhrase($boolFlag) {
        $this->autoReasonPhrase = (bool) $boolFlag;
    }
    
    private function setErrorLogFile($filePath) {
        $this->errorLogFile = $filePath;
    }
    
    private function setHandleBeforeBody($boolFlag) {
        $this->handleBeforeBody = (bool) $boolFlag;
    }
    
    private function setDefaultCharset($charset) {
        $this->defaultCharset = $charset;
    }
    
    private function setSendServerToken($boolFlag) {
        $this->sendServerToken = (bool) $boolFlag;
    }
    
    private function setDisableKeepAlive($boolFlag) {
        $this->disableKeepAlive = (bool) $boolFlag;
    }
    
    private function setDontCombineHeaders(array $headers) {
        if ($headers) {
            $headers = array_change_key_case(array_flip($headers), CASE_UPPER);
        }
        
        $this->dontCombineHeaders = $headers ? array_map(function() { return 1; }, $headers) : [];
    }
    
    private function setNormalizeMethodCase($boolFlag) {
        $this->normalizeMethodCase = (bool) $boolFlag;
    }
    
    private function setAllowedMethods(array $methods) {
        $this->allowedMethods = array_map(function() { return 1; }, array_flip($methods));
        $this->allowedMethods[Method::GET] = 1;
        $this->allowedMethods[Method::HEAD] = 1;
    }
    
    private function setDefaultHost($hostId) {
        $hostId = $host->getId();
        
        if (isset($this->hosts[$hostId])) {
            $this->defaultHost = $this->hosts[$hostId];
        } else {
            throw new \DomainException(
                'Cannot assign default host: no registered hosts match ' . $hostId
            );
        }
    }
    
    function registerMod($hostId, $mod) {
        foreach ($this->selectApplicableModHosts($hostId) as $host) {
            $hostId = $host->getId();
            
            if ($mod instanceof Mods\OnRequestMod) {
                $this->onRequestMods[$hostId][] = $mod;
                usort($this->onRequestMods[$hostId], [$this, 'onRequestModPrioritySort']);
            }
            
            if ($mod instanceof Mods\BeforeResponseMod) {
                $this->beforeResponseMods[$hostId][] = $mod;
                usort($this->beforeResponseMods[$hostId], [$this, 'beforeResponseModPrioritySort']);
            }
            
            if ($mod instanceof Mods\AfterResponseMod) {
                $this->afterResponseMods[$hostId][] = $mod;
                usort($this->afterResponseMods[$hostId], [$this, 'afterResponseModPrioritySort']);
            }
        }
    }
    
    private function selectApplicableModHosts($hostId) {
        if ($hostId == '*') {
            $hosts = $this->hosts;
        } elseif (substr($hostId, 0, 2) == '*:') {
            $port = substr($hostId, 2);
            $hosts = array_filter($this->hosts, function($host) use ($port) {
                return ($port == '*' || $host->getPort() == $port);
            });
        } elseif (substr($hostId, -2) == ':*') {
            $addr = substr($hostId, 0, -2);
            $hosts = array_filter($this->hosts, function($host) use ($addr) {
                return ($addr == '*' || $host->getInterface() == $addr);
            });
        } elseif (isset($this->hosts[$hostId])) {
            $hosts = [$this->hosts[$hostId]];
        } else {
            $hosts = [];
        }
        
        return $hosts;
    }
    
    private function modPrioritySort($a, $b) {
        return ($a != $b) ? ($a - $b) : 0;
    }
    
    private function onRequestModPrioritySort(Mods\OnRequestMod $modA, Mods\OnRequestMod $modB) {
        $a = $modA->getOnRequestPriority();
        $b = $modB->getOnRequestPriority();
        
        return $this->modPrioritySort($a, $b);
    }
    
    private function beforeResponseModPrioritySort(Mods\BeforeResponseMod $modA, Mods\BeforeResponseMod $modB) {
        $a = $modA->getBeforeResponsePriority();
        $b = $modB->getBeforeResponsePriority();
        
        return $this->modPrioritySort($a, $b);
    }
    
    private function afterResponseModPrioritySort(Mods\AfterResponseMod $modA, Mods\AfterResponseMod $modB) {
        $a = $modA->getAfterResponsePriority();
        $b = $modB->getAfterResponsePriority();
        
        return $this->modPrioritySort($a, $b);
    }
    
}































