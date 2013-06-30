<?php

namespace Aerys;

use Amp\Reactor,
    Amp\TcpServer,
    Aerys\Parsing\Parser,
    Aerys\Parsing\MessageParser,
    Aerys\Parsing\PeclMessageParser,
    Aerys\Parsing\ParseException,
    Aerys\Writing\WriterFactory,
    Aerys\Writing\ResourceException,
    Aerys\Mods\OnHeadersMod,
    Aerys\Mods\BeforeResponseMod,
    Aerys\Mods\AfterResponseMod;

class Server extends TcpServer {
    
    const SERVER_SOFTWARE = 'Aerys/0.1.0-devel';
    const HTTP_DATE = 'D, d M Y H:i:s T';
    
    private $hosts = [];
    private $clients;
    private $requestIdClientMap = [];
    private $cachedClientCount = 0;
    private $lastRequestId = 0;
    
    private $onHeadersMods = [];
    private $beforeResponseMods = [];
    private $afterResponseMods = [];
    private $insideBeforeResponseModLoop = FALSE;
    private $insideAfterResponseModLoop = FALSE;
    
    private $logErrorsTo;
    private $maxConnections = 2500;
    private $maxRequests = 150;
    private $keepAliveTimeout = 5;
    private $defaultContentType = 'text/html';
    private $defaultCharset = 'utf-8';
    private $autoReasonPhrase = TRUE;
    private $sendServerToken = FALSE;
    private $disableKeepAlive = FALSE;
    private $socketSoLinger = NULL;
    private $defaultHost;
    private $normalizeMethodCase = TRUE;
    private $requireBodyLength = FALSE;
    private $maxHeaderBytes = 8192;
    private $maxBodyBytes = 10485760;
    private $bodySwapSize = 2097152;
    private $allowedMethods = ['GET', 'HEAD', 'OPTIONS', 'TRACE', 'PUT', 'POST', 'PATCH', 'DELETE'];
    
    private $errorStream;
    private $canUsePeclHttp;
    private $ioGranularity = 262144;
    
    function __construct(Reactor $reactor, WriterFactory $wf = NULL) {
        $this->reactor = $reactor;
        $this->writerFactory = $wf ?: new WriterFactory;
        $this->clients = new \SplObjectStorage;
        
        $this->canUsePeclHttp = (extension_loaded('http') && function_exists('http_parse_headers'));
        $this->allowedMethods = array_combine($this->allowedMethods, array_fill(0, count($this->allowedMethods), 1));
    }
    
    function addHost(Host $host) {
        $hostId = $host->getId();
        $this->hosts[$hostId] = $host;
    }
    
    function start() {
        if ($this->canStart()) {
            $this->errorStream = $this->logErrorsTo ? fopen($this->logErrorsTo, 'ab+') : STDERR;
            parent::start();
        }
    }
    
    private function canStart() {
        if ($this->isStarted) {
            $result = FALSE;
        } elseif (empty($this->hosts)) {
            throw new \RuntimeException(
                'Cannot start server: no hosts registered'
            );
        } else {
            $result = TRUE;
        }
        
        return $result;
    }
    
    protected function accept($server) {
        while ($clientSock = @stream_socket_accept($server, $timeout = 0)) {
            if (++$this->cachedClientCount === $this->maxConnections) {
                $this->pause();
            }
            $this->onClient($clientSock);
        }
    }
    
    protected function acceptTls($server) {
        $serverId = (int) $server;
        
        while ($clientSock = @stream_socket_accept($server, $timeout = 0)) {
            if (++$this->cachedClientCount === $this->maxConnections) {
                $this->pause();
            }
            $clientId = (int) $clientSock;
            $this->pendingTlsClients[$clientId] = NULL;
            
            if (!$this->doTlsHandshake($clientSock, $trigger = NULL)) {
                $handshakeSub = $this->reactor->onReadable($clientSock, function ($clientSock, $trigger) {
                    $this->doTlsHandshake($clientSock, $trigger);
                }, $this->tlsHandshakeTimeout);
                
                $this->pendingTlsClients[$clientId] = $handshakeSub;
            }
        }
    }
    
    protected function failTlsConnection($clientSock) {
        if ($this->cachedClientCount-- === $this->maxConnections) {
            $this->resume();
        }
        parent::failTlsConnection($clientSock);
    }
    
    protected function onClient($socket) {
        stream_set_blocking($socket, FALSE); // @TODO We may want to change this in AMP so that sockets are non-blocking when they arrive
        
        $client = new Client;
        $client->socket = $socket;
        
        $rawServerName = stream_socket_get_name($socket, FALSE);
        list($client->serverAddress, $client->serverPort) = $this->parseSocketName($rawServerName);
        
        $rawClientName = stream_socket_get_name($socket, TRUE);
        list($client->clientAddress, $client->clientPort) = $this->parseSocketName($rawServerName);
        
        $client->isEncrypted = isset(stream_context_get_options($socket)['ssl']);
        
        $client->parser = $this->canUsePeclHttp
            ? new PeclMessageParser(Parser::MODE_REQUEST)
            : new MessageParser(Parser::MODE_REQUEST);
        
        $onHeaders = function($requestArr) use ($client) { $this->onPreBodyHeaders($client, $requestArr); };
        $onReadable = function($socket, $trigger) use ($client) {
            return ($trigger === Reactor::READ) ? $this->read($client) : $this->timeout($client);
        };
        
        $client->parser->setOptions([
            'maxHeaderBytes' => $this->maxHeaderBytes,
            'maxBodyBytes' => $this->maxBodyBytes,
            'bodySwapSize' => $this->bodySwapSize,
            'preBodyHeadersCallback' => $onHeaders
        ]);
        
        $client->readSubscription = $this->reactor->onReadable($socket, $onReadable, $this->keepAliveTimeout);
        
        $this->clients->attach($client);
        $this->cachedClientCount++;
    }
    
    private function parseSocketName($name) {
        $portStartPos = strrpos($name, ':');
        $addr = substr($name, 0, $portStartPos);
        $port = substr($name, $portStartPos + 1);
        
        return [$addr, $port];
    }
    
    private function timeout(Client $client) {
        if (!$client->requests || ltrim($client->parser->getBuffer(), "\r\n")) {
            $this->closeClient($client);
        }
    }
    
    private function read(Client $client) {
        $data = @fread($client->socket, $this->ioGranularity);
        
        if ($data || $data === '0') {
            $this->parseClientData($client, $data);
        } elseif (!is_resource($client->socket) || @feof($client->socket)) {
            $this->closeClient($client);
        }
    }
    
    private function parseClientData(Client $client, $data) {
        try {
            while ($requestArr = $client->parser->parse($data)) {
                $this->onRequest($client, $requestArr);
                $data = '';
            }
        } catch (ParseException $e) {
            $this->onParseError($client, $e->getCode());
        }
    }
    
    private function onPreBodyHeaders(Client $client, array $requestArr) {
        if (!$requestInitArr = $this->initializeRequest($client, $requestArr)) {
            return;
        }
        
        list($requestId, $asgiEnv, $host) = $requestInitArr;
        
        if ($this->requireBodyLength && empty($asgiEnv['CONTENT_LENGTH'])) {
            $client->responses[$requestId] = [Status::LENGTH_REQUIRED, Reason::HTTP_411, [], NULL];
            return;
        }
        
        $hasExpectHeader = !empty($asgiEnv['HTTP_EXPECT']);
        $needsContinue = $hasExpectHeader && !strcasecmp($asgiEnv['HTTP_EXPECT'], '100-continue');
        
        $client->preBodyRequest = [$requestId, $asgiEnv, $host, $needs100Continue];
        $client->requests[$requestId] = $asgiEnv;
        $client->requestHeaderTraces[$requestId] = $requestArr['trace'];
        
        $this->invokeOnHeadersMods($host->getId(), $requestId);
        
        if ($needsContinue && !isset($client->responses[$requestId])) {
            $client->responses[$requestId] = [Status::CONTINUE_100, Reason::HTTP_100, [], NULL];
        }
    }
    
    private function initializeRequest(Client $client, array $requestArr) {
        $requestId = ++$this->lastRequestId;
        $this->requestIdClientMap[$requestId] = $client;
        
        $method = $requestArr['method'] = $this->normalizeMethodCase
            ? strtoupper($requestArr['method'])
            : $requestArr['method'];
        
        if (!$host = $this->selectRequestHost($requestArr)) {
            $asgiEnv = $this->generateAsgiEnv($client, $serverName = '?', $requestArr);
            $asgiResponse = $this->generateInvalidHostNameResponse();
            
            $client->requestCount += !isset($client->requests[$requestId]);
            $client->requests[$requestId] = $asgiEnv;
            $client->requestHeaderTraces[$requestId] = $trace;
            $client->responses[$requestId] = $asgiResponse;
            $client->pipeline[$requestId] = NULL;
            
            return NULL;
        }
        
        $asgiEnv = $this->generateAsgiEnv($client, $host->getName(), $requestArr);
        
        $client->requestCount += !isset($client->requests[$requestId]);
        $client->requests[$requestId] = $asgiEnv;
        $client->requestHeaderTraces[$requestId] = $requestArr['trace'];
        
        if (!isset($this->allowedMethods[$method])) {
            $asgiResponse = $this->generateMethodNotAllowedResponse();
        } elseif ($method === 'TRACE') {
            $asgiResponse = $this->generateTraceResponse($requestArr['trace']);
        } elseif ($method === 'OPTIONS' && $requestArr['uri'] === '*') {
            $asgiResponse = $this->generateOptionsResponse();
        } else {
            $asgiResponse = NULL;
        }
        
        if ($asgiResponse) {
            $client->responses[$requestId] = $asgiResponse;
            $result = NULL;
        } else {
            $result = [$requestId, $asgiEnv, $host];
        }
        
        return $result;
    }
    
    private function generateInvalidHostNameResponse() {
        $status = Status::BAD_REQUEST;
        $reason = Reason::HTTP_400 . ': Invalid Host';
        $body = '<html><body><h1>' . $status . ' ' . $reason . '</h1></body></html>';
        $headers = [
            'Content-Type' => 'text/html; charset=iso-8859-1',
            'Content-Length' => strlen($body)
        ];
        
        return [$status, $reason, $headers, $body];
    }
    
    private function generateMethodNotAllowedResponse() {
        return [
            $status = Status::METHOD_NOT_ALLOWED,
            $reason = Reason::HTTP_405,
            $headers = ['Allow' => implode(',', array_keys($this->allowedMethods))],
            $body = NULL
        ];
    }
    
    private function generateTraceResponse($body) {
        $headers = [
            'Content-Length' => strlen($body), 
            'Content-Type' => 'text/plain; charset=utf-8'
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
            $headers = ['Allow' => implode(',', array_keys($this->allowedMethods))],
            $body = NULL
        ];
    }
    
    /**
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec5.html#sec5.2
     */
    private function selectRequestHost(array $requestArr) {
        $protocol = $requestArr['protocol'];
        $requestUri = $requestArr['uri'];
        $headers = array_change_key_case($requestArr['headers'], CASE_UPPER);
        $hostHeader = empty($headers['HOST']) ? NULL : strtolower(current($headers['HOST']));
        
        if (0 === stripos($requestUri, 'http://') || stripos($requestUri, 'https://') === 0) {
            $host = $this->selectHostByAbsoluteUri($requestUri);
        } elseif ($hostHeader !== NULL || $protocol >= 1.1) {
            $host = $this->selectHostByHeader($hostHeader);
        } elseif ($this->defaultHost) {
            $host = $this->defaultHost;
        } else {
            $host = current($this->hosts);
        }
        
        return $host;
    }
    
    /**
     * @TODO Determine how best to allow for forward proxies specifying absolute URIs
     */
    private function selectHostByAbsoluteUri($uri) {
        $urlParts = parse_url($uri);
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
    
    private function generateAsgiEnv(Client $client, $serverName, array $requestArr) {
        $uri = $requestArr['uri'];
        $queryString =  ($uri === '/' || $uri === '*') ? '' : parse_url($uri, PHP_URL_QUERY);
        $scheme = $client->isEncrypted ? 'https' : 'http';
        $body = $requestArr['body'] ?: NULL;
        
        $asgiEnv = [
            'ASGI_VERSION'      => '0.1',
            'ASGI_CAN_STREAM'   => TRUE,
            'ASGI_NON_BLOCKING' => TRUE,
            'ASGI_LAST_CHANCE'  => !$requestArr['headersOnly'],
            'ASGI_ERROR'        => $this->errorStream,
            'ASGI_INPUT'        => $body,
            'ASGI_URL_SCHEME'   => $scheme,
            'SERVER_NAME'       => $serverName,
            'SERVER_PORT'       => $client->serverPort,
            'SERVER_PROTOCOL'   => $requestArr['protocol'],
            'REMOTE_ADDR'       => $client->clientAddress,
            'REMOTE_PORT'       => $client->clientPort,
            'REQUEST_METHOD'    => $requestArr['method'],
            'REQUEST_URI'       => $uri,
            'QUERY_STRING'      => $queryString
        ];
        
        if ($headers = $requestArr['headers']) {
            $headers = array_change_key_case($headers, CASE_UPPER);
        }
        
        if (!empty($headers['CONTENT-TYPE'])) {
            $asgiEnv['CONTENT_TYPE'] = current($headers['CONTENT-TYPE']);
            unset($headers['CONTENT-TYPE']);
        }
        
        if (!empty($headers['CONTENT-LENGTH'])) {
            $asgiEnv['CONTENT_LENGTH'] = current($headers['CONTENT-LENGTH']);
            unset($headers['CONTENT-LENGTH']);
        }
        
        foreach ($headers as $field => $value) {
            $field = 'HTTP_' . str_replace('-', '_', $field);
            $asgiEnv[$field] = isset($value[1]) ? implode(',', $value) : $value[0];
        }
        
        return $asgiEnv;
    }
    
    private function invokeOnHeadersMods($hostId, $requestId) {
        if (isset($this->onHeadersMods[$hostId])) {
            foreach ($this->onHeadersMods[$hostId] as $mod) {
                $mod->onHeaders($requestId);
            }
        }
    }
    
    private function onRequest(Client $client, array $requestArr) {
        if ($client->preBodyRequest) {
            return $this->finalizePreBodyRequest($client, $requestArr);
        } elseif (!$requestInitArr = $this->initializeRequest($client, $requestArr)) {
            return;
        }
        
        list($requestId, $asgiEnv, $host) = $requestInitArr;
        
        $this->invokeOnHeadersMods($host->getId(), $requestId);
        
        if (!isset($client->responses[$requestId])) {
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
            $asgiEnv = $this->generateAsgiEnv($client, $host->getName(), $requestArr);
        }
        
        if ($needsNewRequestId || $hasTrailer) {
            $client->requests[$requestId] = $asgiEnv;
            $client->requestHeaderTraces[$requestId] = $requestArr['trace'];
        }
        
        $this->invokeRequestHandler($requestId, $asgiEnv, $host->getHandler());
    }
    
    private function invokeRequestHandler($requestId, array $asgiEnv, callable $handler) {
        try {
            if ($asgiResponse = $handler($asgiEnv, $requestId)) {
                $this->setResponse($requestId, $asgiResponse);
            }
        } catch (\Exception $e) {
            $this->setResponse($requestId, [
                $status = Status::INTERNAL_SERVER_ERROR,
                $reason = Reason::HTTP_500,
                $headers = [],
                $body = (string) $e
            ]);
        }
    }
    
    private function onParseError(Client $client, $status) {
        $status = $status ?: Status::BAD_REQUEST;
        $reason = $this->getReasonPhrase($status);
        $body = '<html><body><h1>'. $status . ' '. $reason .'</h1></body></html>';
        $headers = [
            'Date' => date(self::HTTP_DATE),
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Length' => strlen($body),
            'Connection' => 'close'
        ];
        
        $requestArr = [
            'method'   => '?',
            'uri'      => '?',
            'protocol' => '1.0',
            'headers'  => []
        ];
        
        $requestId = ++$this->lastRequestId;
        $this->requestIdClientMap[$requestId] = $client;
        
        $asgiEnv = $this->generateAsgiEnv($client, $serverName = '?', $requestArr);
        
        $client->requests[$requestId] = $asgiEnv;
        $client->requestHeaderTraces[$requestId] = '?';
        
        $this->setResponse($requestId, [$status, $reason, $headers, $body]);
    }
    
    private function getReasonPhrase($statusCode) {
        $reasonConst = 'Aerys\\Reason::HTTP_' . $statusCode;
        return defined($reasonConst) ? constant($reasonConst) : '';
    }
    
    function setResponse($requestId, array $asgiResponse) {
        if ($this->insideAfterResponseModLoop) {
            throw new \LogicException(
                'Cannot modify response inside AfterResponseMod loop'
            );
        }
        
        if (!isset($this->requestIdClientMap[$requestId])) {
            return;
        }
        
        $client = $this->requestIdClientMap[$requestId];
        $asgiEnv = $client->requests[$requestId];
        $asgiResponse = $this->normalizeResponse($asgiEnv, $asgiResponse);
        
        if ($this->disableKeepAlive || ($this->maxRequests && $client->requestCount >= $this->maxRequests)) {
            $asgiResponse[2]['CONNECTION'] = 'close';
        }
        
        $client->responses[$requestId] = $asgiResponse;
        
        // The second isset() check for the $requestId's existence is not an accident. Third-party
        // code may have exported the socket while executing mods and if so, we shouldn't proceed.
        // DON'T REMOVE THE ISSET CHECK BELOW!
        if (!$this->insideBeforeResponseModLoop && isset($this->requestIdClientMap[$requestId])) {
            $hostId = $asgiEnv['SERVER_NAME'] . ':' . $asgiEnv['SERVER_PORT'];
            $this->invokeBeforeResponseMods($hostId, $requestId);
            $this->enqueueResponsesForWrite($client);
        }
    }
    
    private function enqueueResponsesForWrite(Client $client) {
        foreach ($client->requests as $requestId => $asgiEnv) {
            if (isset($client->pipeline[$requestId])) {
                $canWrite = TRUE;
            } elseif (isset($client->responses[$requestId])) {
                list($status, $reason, $headers, $body) = $client->responses[$requestId];
                $protocol = $asgiEnv['SERVER_PROTOCOL'];
                $rawHeaders = $this->generateRawHeaders($protocol, $status, $reason, $headers);
                $responseWriter = $this->writerFactory->make($client->socket, $rawHeaders, $body, $protocol);
                $client->pipeline[$requestId] = $responseWriter;
            } else {
                break;
            }
        }
        
        reset($client->requests);
        
        $this->write($client);
    }
    
    private function generateRawHeaders($protocol, $status, $reason, array $headers) {
        $msg = "HTTP/$protocol $status";
        
        if ($reason || $reason === '0') {
            $msg .= " $reason";
        }
        
        $msg .= "\r\n";
        
        foreach ($headers as $header => $value) {
            if ($value === (array) $value) {
                foreach ($value as $nestedValue) {
                    $msg .= "$header: $nestedValue\r\n";
                }
            } else {
                $msg .= "$header: $value\r\n";
            }
        }
        
        $msg .= "\r\n";
        
        return $msg;
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
        
        if ($asgiEnv['SERVER_PROTOCOL'] == '1.0' && empty($asgiEnv['HTTP_CONNECTION'])) {
            $headers['CONNECTION'] = 'close';
        } elseif (isset($asgiEnv['HTTP_CONNECTION'])
            && empty($headers['CONNECTION'])
            && !strcasecmp($asgiEnv['HTTP_CONNECTION'], 'keep-alive')
        ) {
            $headers['CONNECTION'] = 'keep-alive';
        }
        
        if (!isset($headers['DATE']) && $status != Status::CONTINUE_100) {
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
        
        $protocol = ($asgiEnv['SERVER_PROTOCOL'] === '?') ? '1.0' : $asgiEnv['SERVER_PROTOCOL'];
        
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
        if (isset($this->beforeResponseMods[$hostId])) {
            $this->insideBeforeResponseModLoop = TRUE;
            foreach ($this->beforeResponseMods[$hostId] as $mod) {
                $mod->beforeResponse($requestId);
            }
            $this->insideBeforeResponseModLoop = FALSE;
        }
    }
    
    private function write(Client $client) {
        try {
            foreach ($client->pipeline as $requestId => $responseWriter) {
                if (!$responseWriter) {
                    // The next request in the pipeline doesn't have a response yet. We can't continue
                    // because responses must be returned in the order in which they were received.
                    break;
                } elseif ($responseWriter->write()) {
                    $this->afterResponse($client, $requestId);
                } elseif ($client->writeSubscription) {
                    $client->writeSubscription->enable();
                    break;
                } else {
                    $client->writeSubscription = $this->reactor->onWritable($client->socket, function() use ($client) {
                        $this->write($client);
                    });
                    break;
                }
            }
        } catch (ResourceException $e) {
            $this->closeClient($client);
        }
    }
    
    private function afterResponse(Client $client, $requestId) {
        $asgiEnv = $client->requests[$requestId];
        $asgiResponse = $client->responses[$requestId];
        
        $hostId = $asgiEnv['SERVER_NAME'] . ':' . $asgiEnv['SERVER_PORT'];
        $this->invokeAfterResponseMods($hostId, $requestId);
        
        if ($asgiResponse[0] == Status::SWITCHING_PROTOCOLS) {
            $this->clearClientReferences($client);
            $upgradeCallback = $asgiResponse[4];
            $upgradeCallback($client->socket, $asgiEnv);
        } elseif ($this->shouldCloseAfterResponse($asgiEnv, $asgiResponse)) {
            $this->closeClient($client);
        } else {
            $this->dequeueClientPipelineRequest($client, $requestId);
        }
    }
    
    private function dequeueClientPipelineRequest($client, $requestId) {
        unset(
            $client->pipeline[$requestId],
            $client->requests[$requestId],
            $client->responses[$requestId],
            $client->requestHeaderTraces[$requestId],
            $this->requestIdClientMap[$requestId]
        );
        
        // Disable active onWritable stream subscriptions if the pipeline is now empty
        if ($client->writeSubscription && !current($client->pipeline)) {
            $client->writeSubscription->disable();
        }
    }
    
    private function invokeAfterResponseMods($hostId, $requestId) {
        if (isset($this->afterResponseMods[$hostId])) {
            $this->insideAfterResponseModLoop = TRUE;
            foreach ($this->afterResponseMods[$hostId] as $mod) {
                $mod->afterResponse($requestId);
            }
            $this->insideAfterResponseModLoop = FALSE;
        }
    }
    
    private function shouldCloseAfterResponse(array $asgiEnv, array $asgiResponse) {
        $headers = $asgiResponse[2];
        
        if (isset($headers['CONNECTION']) && !strcasecmp('close', $headers['CONNECTION'])) {
            $result = TRUE;
        } elseif (isset($asgiEnv['HTTP_CONNECTION']) && !strcasecmp('close', $asgiEnv['HTTP_CONNECTION'])) {
            $result = TRUE;
        } elseif ($asgiEnv['SERVER_PROTOCOL'] == '1.0' && !isset($asgiEnv['HTTP_CONNECTION'])) {
            $result = TRUE;
        } else {
            $result = FALSE;
        }
        
        return $result;
    }
    
    private function clearClientReferences(Client $client) {
        if ($client->requests) {
            foreach (array_keys($client->requests) as $requestId) {
                unset($this->requestIdClientMap[$requestId]);
            }
        }
        
        $client->readSubscription->cancel();
        
        if ($client->writeSubscription) {
            $client->writeSubscription->cancel();
        }
        
        $this->clients->detach($client);
        
        if ($this->cachedClientCount-- === $this->maxConnections) {
            $this->resume();
        }
    }
    
    private function closeClient(Client $client) {
        $this->clearClientReferences($client);
        if (is_resource($client->socket)) {
            $this->closeSocket($client->socket);
        }
    }
    
    private function closeSocket($socket) {
        if ($this->socketSoLinger !== NULL) {
            $this->closeSocketWithSoLinger($socket);
        } else {
            @fclose($socket);
        }
    }
    
    private function closeSocketWithSoLinger($socket) {
        @stream_socket_enable_crypto($socket, FALSE);
        
        $socket = socket_import_stream($socket);
        
        if ($this->socketSoLinger) {
            socket_set_block($socket);
        }
        
        socket_set_option($socket, SOL_SOCKET, SO_LINGER, [
            'l_onoff' => 1,
            'l_linger' => $this->socketSoLinger
        ]);
        
        socket_close($socket);
    }
    
    function getRequest($requestId) {
        if (isset($this->requestIdClientMap[$requestId])) {
            $client = $this->requestIdClientMap[$requestId];
        } else {
            throw new \DomainException(
                'Request ID does not exist: ' . $requestId
            );
        }
        
        return $client->requests[$requestId];
    }
    
    function getResponse($requestId) {
        if (isset($this->requestIdClientMap[$requestId])) {
            $client = $this->requestIdClientMap[$requestId];
        } else {
            throw new \DomainException(
                'Request ID does not exist: ' . $requestId
            );
        }
        
        return $client->responses[$requestId];
    }
    
    function getTrace($requestId) {
        if (isset($this->requestIdClientMap[$requestId])) {
            $client = $this->requestIdClientMap[$requestId];
        } else {
            throw new \DomainException(
                'Request ID does not exist: ' . $requestId
            );
        }
        
        return $client->requestHeaderTraces[$requestId];
    }
    
    function getErrorStream() {
        return $this->errorStream;
    }
    
    function setOption($option, $value) {
        $setter = 'set' . ucfirst($option);
        if (method_exists($this, $setter)) {
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
    
    private function setMaxRequests($maxRequests) {
        $this->maxRequests = (int) $maxRequests;
    }
    
    private function setKeepAliveTimeout($seconds) {
        if (!$this->keepAliveTimeout = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 10
        ]])) {
            $this->keepAliveTimeout = -1;
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
    
    private function setBodySwapSize($bytes) {
        $this->bodySwapSize = (int) $bytes;
    }
    
    private function setDefaultContentType($mimeType) {
        $this->defaultContentType = $mimeType;
    }
    
    private function setDefaultCharset($charset) {
        $this->defaultCharset = $charset;
    }
    
    private function setAutoReasonPhrase($boolFlag) {
        $this->autoReasonPhrase = (bool) $boolFlag;
    }
    
    private function setLogErrorsTo($filePath) {
        $this->logErrorsTo = $filePath;
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
    
    private function setSocketSoLinger($seconds) {
        $this->socketSoLinger = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => NULL
        ]]);
    }
    
    private function setAllowedMethods(array $methods) {
        $this->allowedMethods = array_map(function() { return 1; }, array_flip($methods));
        $this->allowedMethods['GET'] = 1;
        $this->allowedMethods['HEAD'] = 1;
    }
    
    private function setDefaultHost($hostId) {
        if (isset($this->hosts[$hostId])) {
            $this->defaultHost = $this->hosts[$hostId];
        } else {
            throw new \DomainException(
                'Cannot assign default host: no hosts match: ' . $hostId
            );
        }
    }
    
    function registerMod($hostId, $mod) {
        foreach ($this->selectApplicableModHosts($hostId) as $host) {
            $hostId = $host->getId();
            
            if ($mod instanceof OnHeadersMod) {
                $this->onHeadersMods[$hostId][] = $mod;
                usort($this->onHeadersMods[$hostId], [$this, 'onHeadersModPrioritySort']);
            }
            
            if ($mod instanceof BeforeResponseMod) {
                $this->beforeResponseMods[$hostId][] = $mod;
                usort($this->beforeResponseMods[$hostId], [$this, 'beforeResponseModPrioritySort']);
            }
            
            if ($mod instanceof AfterResponseMod) {
                $this->afterResponseMods[$hostId][] = $mod;
                usort($this->afterResponseMods[$hostId], [$this, 'afterResponseModPrioritySort']);
            }
        }
        
        return $this;
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
    
    private function onHeadersModPrioritySort(OnHeadersMod $modA, OnHeadersMod $modB) {
        $a = $modA->getOnHeadersPriority();
        $b = $modB->getOnHeadersPriority();
        
        return $this->modPrioritySort($a, $b);
    }
    
    private function beforeResponseModPrioritySort(BeforeResponseMod $modA, BeforeResponseMod $modB) {
        $a = $modA->getBeforeResponsePriority();
        $b = $modB->getBeforeResponsePriority();
        
        return $this->modPrioritySort($a, $b);
    }
    
    private function afterResponseModPrioritySort(AfterResponseMod $modA, AfterResponseMod $modB) {
        $a = $modA->getAfterResponsePriority();
        $b = $modB->getAfterResponsePriority();
        
        return $this->modPrioritySort($a, $b);
    }
    
}

