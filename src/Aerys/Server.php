<?php

namespace Aerys;

use Amp\Reactor,
    Amp\Server\TcpServer,
    Aerys\Parsing\TempEntityWriter;

class Server {
    
    const SERVER_SOFTWARE = 'Aerys/0.0.1';
    const HTTP_DATE = 'D, d M Y H:i:s T';
    const WILDCARD = '*';
    const UNKNOWN = '?';
    
    private $reactor;
    private $tcpServers;
    private $hosts = [];
    private $pipelineFactory;
    private $errorStream;
    
    private $pipelines = [];
    private $pipelinesRequiringWrite = [];
    private $readSubscriptions = [];
    private $requestIdPipelineMap = [];
    private $closeInProgress = [];
    private $cachedClientCount = 0;
    private $lastRequestId = 0;
    
    private $isListening = FALSE;
    private $isAcceptEnabled = TRUE;
    private $insideBeforeResponseModLoop = FALSE;
    private $insideAfterResponseModLoop = FALSE;
    
    private $onRequestMods = [];
    private $beforeResponseMods = [];
    private $afterResponseMods = [];
    
    private $autoWriteInterval = 0.025; // @TODO add option setter
    private $logErrorsTo = 'php://stderr';
    private $maxConnections = 0;
    private $maxRequestsPerSession = 150;
    private $keepAliveTimeout = 10;
    private $maxStartLineSize = 2048;
    private $maxHeadersSize = 8192;
    private $maxEntityBodySize = 2097152;
    private $tempEntityDir = NULL;
    private $defaultContentType = 'text/html';
    private $defaultCharset = 'utf-8';
    private $autoReasonPhrase = TRUE;
    private $handleBeforeBody = FALSE;
    private $sendServerToken = FALSE;
    private $disableKeepAlive = FALSE;
    private $soLinger = NULL;
    private $defaultHost;
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
    
    function __construct(Reactor $reactor, PipelineFactory $pf = NULL) {
        $this->reactor = $reactor;
        $this->tcpServers = new \SplObjectStorage;
        $this->pipelineFactory = $pf ?: new PipelineFactory;
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
        if ($this->isListening) {
            return;
        }
        
        $this->isListening = TRUE;
        $this->errorStream = fopen($this->logErrorsTo, 'ab+');
        
        foreach ($this->tcpServers as $tcpServer) {
            $tcpServer->listen(function($clientSock) {
                $this->accept($clientSock);
            });
            
            $listeningOn = $tcpServer->getAddress() . ':' . $tcpServer->getPort();
            echo 'Server listening on ', $listeningOn, PHP_EOL;
        }
        
        /*
        $diagnostics = $this->reactor->repeat(function() {
            //echo time(), ' (', $this->cachedClientCount, ")\n";
            var_dump(memory_get_peak_usage() / 1048576);
            var_dump(memory_get_usage() / 1048576);
        }, $delay = 3);
        */
        
        $this->reactor->repeat(function() { $this->autoWrite(); }, $this->autoWriteInterval);
        
        $this->reactor->run();
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
        $this->isAcceptEnabled = FALSE;
    }
    
    private function enableNewClients() {
        foreach ($this->servers as $server) {
            $server->enable();
        }
        $this->isAcceptEnabled = TRUE;
    }
    
    private function autoWrite() {
        foreach ($this->pipelinesRequiringWrite as $pipeline) {
            $this->write($pipeline);
        }
    }
    
    private function write(Pipeline $pipeline) {
        try {
            $pipelineWriteResult = $pipeline->write();
            
            if ($pipelineWriteResult === 0) {
                $this->afterResponse($pipeline);
                $pipelineId = $pipeline->getId();
                unset($this->pipelinesRequiringWrite[$pipelineId]);
            } elseif ($pipelineWriteResult > 0) {
                $this->afterResponse($pipeline);
            }
        } catch (Writing\ResourceWriteException $e) {
            $this->close($pipeline);
        }
    }
    
    private function accept($clientSock) {
        $pipeline = $this->pipelineFactory->makePipeline($clientSock);
        
        $onHeaders = function(array $parsedRequest) use ($pipeline) {
            $this->onHeaders($pipeline, $parsedRequest);
        };
        
        $pipeline->setParseOptions([
            'maxStartLineBytes' => $this->maxStartLineSize,
            'maxHeaderBytes' => $this->maxHeadersSize,
            'maxBodyBytes' => $this->maxEntityBodySize,
            'onHeadersCallback' => $onHeaders
        ]);
        
        $pipelineId = $pipeline->getId();
        $this->pipelines[$pipelineId] = $pipeline;
        
        $this->readSubscriptions[$pipelineId] = $this->reactor->onReadable($clientSock,
            function ($clientSock, $trigger) use ($pipeline) {
                $this->onReadable($trigger, $pipeline);
            },
            $this->keepAliveTimeout
        );
        
        ++$this->cachedClientCount;
        
        if ($this->maxConnections && $this->cachedClientCount >= $this->maxConnections) {
            $this->disableNewClients();
        }
        
        if ($this->pipelinesRequiringWrite) {
            $this->autoWrite();
        }
    }
    
    private function onReadable($triggeredBy, Pipeline $pipeline) {
        if ($triggeredBy == Reactor::TIMEOUT) {
            return $this->handleReadTimeout($pipeline);
        }
        
        try {
            if ($parsedRequest = $pipeline->parse()) {
                $this->onRequest($pipeline, $parsedRequest);
            }
        } catch (Parsing\ResourceReadException $e) {
            $this->close($pipeline);
        } catch (Parsing\StartLineSizeException $e) {
            $this->handleRequestParseError($pipeline, Status::REQUEST_URI_TOO_LONG);
        } catch (Parsing\HeaderSizeException $e) {
            $this->handleRequestParseError($pipeline, Status::REQUEST_HEADER_FIELDS_TOO_LARGE);
        } catch (Parsing\EntitySizeException $e) {
            $this->handleRequestParseError($pipeline, Status::REQUEST_ENTITY_TOO_LARGE);
        } catch (Parsing\ProtocolNotSupportedException $e) {
            $this->handleRequestParseError($pipeline, Status::HTTP_VERSION_NOT_SUPPORTED);
        } catch (Parsing\ParseException $e) {
            $this->handleRequestParseError($pipeline, Status::BAD_REQUEST);
        }
        
        if ($this->pipelinesRequiringWrite) {
            $this->autoWrite();
        }
    }
    
    private function handleReadTimeout(Pipeline $pipeline) {
        if ($pipeline->hasUnfinishedRead() || !$pipeline->hasRequestsAwaitingResponse()) {
            $this->close($pipeline);
        }
    }
    
    private function handleRequestParseError(Pipeline $pipeline, $status) {
        $requestId = ++$this->lastRequestId;
        $this->requestIdPipelineMap[$requestId] = $pipeline;
        
        // Generate a stand-in parsed request since there was a problem with the real one
        $parsedRequest = [
            'method'   => self::UNKNOWN,
            'uri'      => self::UNKNOWN,
            'protocol' => '1.0',
            'headers'  => []
        ];
        
        // Generate a placeholder $asgiEnv from our stand-in parsed request
        $asgiEnv = $this->generateAsgiEnv($pipeline, '?', $parsedRequest);
        
        $pipeline->setRequest($requestId, $asgiEnv);
        
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
    
    private function onHeaders(Pipeline $pipeline, array $parsedRequest) {
        if (!$requestStruct = $this->initializeNewRequest($pipeline, $parsedRequest)) {
            return;
        }
        
        list($requestId, $asgiEnv, $host) = $requestStruct;
        
        $tempEntityPath = tempnam($this->tempEntityDir, 'aerys');
        $tempEntityWriter = new TempEntityWriter($tempEntityPath);
        $pipeline->setTempEntityWriter($tempEntityWriter);
        $this->tempEntityWriters[$requestId] = $tempEntityWriter;
        
        $asgiEnv['ASGI_INPUT'] = $tempEntityWriter->getResource();
        $asgiEnv['ASGI_LAST_CHANCE'] = FALSE;
        
        $needs100Continue = isset($asgiEnv['HTTP_EXPECT']) && !strcasecmp($asgiEnv['HTTP_EXPECT'], '100-continue');
        
        $pipeline->addPreBodyRequest($requestId, $asgiEnv, $host, $needs100Continue);
        
        $this->invokeOnRequestMods($host->getId(), $requestId);
        
        if ($pipeline->hasResponse($requestId)) {
            $pipeline->incrementRequestCount();
        } elseif ($this->handleBeforeBody
            && !$this->invokeRequestHandler($requestId, $asgiEnv, $host->getHandler())
            && $needs100Continue
        ) {
            $this->setResponse($requestId, [Status::CONTINUE_100, Reason::HTTP_100, [], NULL]);
        } elseif (!$this->handleBeforeBody && $needs100Continue) {
            $this->setResponse($requestId, [Status::CONTINUE_100, Reason::HTTP_100, [], NULL]);
        }
    }
    
    private function initializeNewRequest(Pipeline $pipeline, array $parsedRequest) {
        $requestId = ++$this->lastRequestId;
        $this->requestIdPipelineMap[$requestId] = $pipeline;
        
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
            $asgiEnv = $this->generateAsgiEnv($pipeline, $host->getName(), $parsedRequest);
        } else {
            $asgiEnv = $this->generateAsgiEnv($pipeline, self::UNKNOWN, $parsedRequest);
            
            $status = Status::BAD_REQUEST;
            $reason = Reason::HTTP_400 . ': Invalid Host';
            $body = '<html><body><h1>' . $status . ' ' . $reason . '</h1></body></html>';
            $headers = [
                'Content-Type' => 'text/html; charset=iso-8859-1',
                'Content-Length' => strlen($body)
            ];
            $response = [$status, $reason, $headers, $body];
        }
        
        $pipeline->setRequest($requestId, $asgiEnv);
        
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
        } elseif ($hostHeader !== NULL || $protocol >= 1.1) {
            $host = $this->selectHostByHeader($hostHeader);
        } elseif ($protocol == '1.0') {
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
                $result = TRUE;
            } else {
                $result = FALSE;
            }
        } catch (\Exception $e) {
            $serverName = $asgiEnv['SERVER_NAME'];
            $requestUri = $asgiEnv['REQUEST_URI'];
            
            $this->logUserlandError($e, $serverName, $requestUri);
            $this->setResponse($requestId, [Status::INTERNAL_SERVER_ERROR, '', [], NULL]);
            
            $result = TRUE;
        }
        
        return $result;
    }
    
    private function getReasonPhrase($statusCode) {
        $reasonConst = 'Aerys\\Http\\Reason::HTTP_' . $statusCode;
        return defined($reasonConst) ? constant($reasonConst) : '';
    }
    
    private function generateAsgiEnv(Pipeline $pipeline, $serverName, $parsedRequest) {
        $uri = $parsedRequest['uri'];
        $queryString =  ($uri == '/' || $uri == '*') ? '' : parse_url($uri, PHP_URL_QUERY);
        $method = $this->normalizeMethodCase ? strtoupper($parsedRequest['method']) : $parsedRequest['method'];
        $headers = $parsedRequest['headers'];
        $contentType = isset($headers['CONTENT-TYPE']) ? $headers['CONTENT-TYPE'] : '';
        $contentLength = isset($headers['CONTENT-LENGTH']) ? $headers['CONTENT-LENGTH'] : '';
        $scheme = isset(stream_context_get_options($pipeline->getSocket())['ssl']) ? 'https' : 'http';
        
        $asgiEnv = [
            'SERVER_NAME'       => $serverName,
            'SERVER_PORT'       => $pipeline->getServerPort(),
            'SERVER_PROTOCOL'   => $parsedRequest['protocol'],
            'REMOTE_ADDR'       => $pipeline->getAddress(),
            'REMOTE_PORT'       => $pipeline->getPort(),
            'REQUEST_METHOD'    => $method,
            'REQUEST_URI'       => $uri,
            'QUERY_STRING'      => $queryString,
            'CONTENT_TYPE'      => $contentType,
            'CONTENT_LENGTH'    => $contentLength,
            'ASGI_VERSION'      => 0.1,
            'ASGI_URL_SCHEME'   => $scheme,
            'ASGI_INPUT'        => NULL,
            'ASGI_ERROR'        => $this->errorStream,
            'ASGI_CAN_STREAM'   => TRUE,
            'ASGI_NON_BLOCKING' => TRUE,
            'ASGI_LAST_CHANCE'  => TRUE
        ];
        
        foreach ($headers as $field => $value) {
            $field = strtoupper($field);
            $field = 'HTTP_' . str_replace('-',  '_', $field);
            $value = ($value === (array) $value) ? implode(',', $value) : $value;
            $asgiEnv[$field] = $value;
        }
        
        return $asgiEnv;
    }
    
    private function onRequest(Pipeline $pipeline, array $parsedRequest) {
        if ($hasPreBodyRequest = $pipeline->hasPreBodyRequest()) {
            list($requestId, $asgiEnv, $host, $needsNewRequestId) = $pipeline->shiftPreBodyRequest();
            $asgiEnv['ASGI_LAST_CHANCE'] = TRUE;
            rewind($asgiEnv['ASGI_INPUT']);
        } elseif ($requestStruct = $this->initializeNewRequest($pipeline, $parsedRequest)) {
            list($requestId, $asgiEnv, $host) = $requestStruct;
        } else {
            return;
        }
        
        if ($hasPreBodyRequest && $needsNewRequestId) {
            $requestId = ++$this->lastRequestId;
            $this->requestIdPipelineMap[$requestId] = $pipeline;
        }
        
        $pipeline->incrementRequestCount();
        
        // Headers may have changed in the presence of trailers, so regenerate the request environment
        $hasTrailerHeader = !empty($asgiEnv['HTTP_TRAILER']);
        if ($hasPreBodyRequest && $hasTrailerHeader) {
            $asgiEnv = $this->generateAsgiEnv($pipeline, $host->getName(), $parsedRequest);
            $pipeline->setRequest($requestId, $asgiEnv);
        }
        
        if (!$hasPreBodyRequest || $hasTrailerHeader) {
            $this->invokeOnRequestMods($host->getId(), $requestId);
        }
        
        $this->invokeRequestHandler($requestId, $asgiEnv, $host->getHandler());
    }
    
    private function afterResponse(Pipeline $pipeline) {
        list($requestId, $asgiEnv, $asgiResponse) = $pipeline->getFront();
        
        $hostId = $asgiEnv['SERVER_NAME'] . ':' . $pipeline->getServerPort();
        $this->invokeAfterResponseMods($hostId, $requestId);
        
        if ($asgiResponse[0] == Status::SWITCHING_PROTOCOLS) {
            $upgradeCallback = $asgiResponse[4];
            $clientSock = $this->export($pipeline);
            
            try {
                $upgradeCallback($clientSock, $asgiEnv);
            } catch (\Exception $e) {
                $serverName = $asgiEnv['SERVER_NAME'];
                $requestUri = $asgiEnv['REQUEST_URI'];
                
                $this->logUserlandError($e, $serverName, $requestUri);
            }
        } elseif ($this->shouldCloseAfterResponse($asgiEnv, $asgiResponse)) {
            $this->close($pipeline);
        } else {
            $pipeline->shiftFront();
            unset(
                $this->requestIdPipelineMap[$requestId],
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
        } elseif ($asgiEnv['SERVER_PROTOCOL'] == '1.0' && !isset($asgiEnv['HTTP_CONNECTION'])) {
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
    
    private function close(Pipeline $pipeline) {
        $clientSock = $this->export($pipeline);
        
        $isResource = is_resource($clientSock);
        
        if ($isResource && $this->soLinger !== NULL) {
            $this->closeWithSoLinger($clientSock);
        } elseif ($isResource) {
            stream_socket_shutdown($clientSock, STREAM_SHUT_RDWR);
            fclose($clientSock);
        }
    }
    
    private function export(Pipeline $pipeline) {
        if ($requestIds = $pipeline->getRequestIds()) {
            foreach ($requestIds as $requestId) {
                unset(
                    $this->requestIdPipelineMap[$requestId],
                    $this->tempEntityWriters[$requestId]
                );
            }
        }
        
        $pipelineId = $pipeline->getId();
        
        $readSubscription = $this->readSubscriptions[$pipelineId];
        $readSubscription->cancel();
        
        unset(
            $this->pipelines[$pipelineId],
            $this->pipelinesRequiringWrite[$pipelineId],
            $this->readSubscriptions[$pipelineId]
        );
        
        --$this->cachedClientCount;
        
        if (!$this->isAcceptEnabled && $this->cachedClientCount < $this->maxConnections) {
            $this->enableNewClients();
        }
        
        return $pipeline->getSocket();
    }
    
    private function closeWithSoLinger($clientSock) {
        // socket extension can't import stream if it has crypto enabled
        @stream_socket_enable_crypto($clientSock, FALSE);
        $rawSock = socket_import_stream($clientSock);
        
        socket_set_block($rawSock);
        socket_set_option($rawSock, SOL_SOCKET, SO_LINGER, [
            'l_onoff' => 1,
            'l_linger' => $this->soLinger
        ]);
        
        socket_close($rawSock);
    }
    
    function setResponse($requestId, array $asgiResponse) {
        if (!isset($this->requestIdPipelineMap[$requestId])) {
            return;
        } elseif ($this->insideAfterResponseModLoop) {
            throw new \LogicException(
                'Cannot modify response; message already sent'
            );
        }
        
        $pipeline = $this->requestIdPipelineMap[$requestId];
        
        $asgiEnv = $pipeline->getRequest($requestId);
        $asgiResponse = $this->normalizeResponse($asgiEnv, $asgiResponse);
        
        $is100Continue = ($asgiResponse[0] == Status::CONTINUE_100);
        
        if ($this->disableKeepAlive || (
            !$is100Continue
            && $this->maxRequestsPerSession
            && $pipeline->getRequestCount() >= $this->maxRequestsPerSession
        )) {
            $asgiResponse[2]['CONNECTION'] = 'close';
        }
        
        $pipeline->setResponse($requestId, $asgiResponse);
        
        if ($this->insideBeforeResponseModLoop) {
            return;
        }
        
        $hostId = $asgiEnv['SERVER_NAME'] . ':' . $pipeline->getServerPort();
        
        // Reload the response array in case it was altered by beforeResponse mods ...
        if ($this->invokeBeforeResponseMods($hostId, $requestId)) {
            $asgiResponse = $pipeline->getResponse($requestId);
        }
        
        if ($asgiResponse[0] == Status::SWITCHING_PROTOCOLS) {
            $asgiResponse = $this->prepForProtocolUpgrade($asgiResponse);
            $pipeline->setResponse($requestId, $asgiResponse);
        }
        
        if (!$pipeline->enqueueResponsesForWrite()) {
            return;
        }
        
        $pipelineId = $pipeline->getId();
        $writeResult = $pipeline->write();
        
        if ($writeResult < 0) {
            $this->pipelinesRequiringWrite[$pipelineId] = $pipeline;
        } elseif ($writeResult === 0) {
            unset($this->pipelinesRequiringWrite[$pipelineId]);
            $this->afterResponse($pipeline);
        } else {
            $this->afterResponse($pipeline);
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
            ? '1.0'
            : $asgiEnv['SERVER_PROTOCOL'];
        
        if ($hasBody && !$hasContentLength && is_string($body)) {
            $headers['CONTENT-LENGTH'] = strlen($body);
        } elseif ($hasBody && !$hasContentLength && is_resource($body)) {
            fseek($body, 0, SEEK_END);
            $headers['CONTENT-LENGTH'] = ftell($body);
            rewind($body);
        } elseif ($hasBody && $protocol >= 1.1 && !$isChunked && $isIterator) {
            $headers['TRANSFER-ENCODING'] = 'chunked';
        } elseif ($hasBody && !$hasContentLength && $protocol < 1.1 && $isIterator) {
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
        if (!isset($this->requestIdPipelineMap[$requestId])) {
            throw new \DomainException(
                'Request ID does not exist: ' . $requestId
            );
        }
        
        $pipeline = $this->requestIdPipelineMap[$requestId];
        
        if ($pipeline->hasResponse($requestId)) {
            return $pipeline->getResponse($requestId);
        } else {
            throw new \DomainException(
                "Request ID $requestId does not exist or has no assigned response"
            );
        }
    }
    
    function getRequest($requestId) {
        if (!isset($this->requestIdPipelineMap[$requestId])) {
            throw new \DomainException(
                'Request ID does not exist: ' . $requestId
            );
        }
        
        $pipeline = $this->requestIdPipelineMap[$requestId];
        
        if ($pipeline->hasRequest($requestId)) {
            return $pipeline->getRequest($requestId);
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
    
    private function setKeepAliveTimeout($seconds) {
        $this->keepAliveTimeout = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 2,
            'default' => 10
        ]]);
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
    
    private function setLogErrorsTo($filePath) {
        $this->logErrorsTo = $filePath;
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
    
    private function setNormalizeMethodCase($boolFlag) {
        $this->normalizeMethodCase = (bool) $boolFlag;
    }
    
    private function setSoLinger($seconds) {
        $this->soLinger = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => NULL
        ]]);
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
                return ($addr == '*' || $host->getAddress() == $addr);
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

