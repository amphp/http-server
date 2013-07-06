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
    
    private $onHeadersMods;
    private $beforeResponseMods;
    private $afterResponseMods;
    private $insideBeforeResponseModLoop = FALSE;
    private $insideAfterResponseModLoop = FALSE;
    
    private $logErrorsTo;
    private $maxConnections = 1500;
    private $maxRequests = 150;
    private $keepAliveTimeout = 10;
    private $defaultContentType = 'text/html';
    private $defaultTextCharset = 'utf-8';
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
    private $socketReadGranularity = 262144;
    
    function __construct(Reactor $reactor, WriterFactory $wf = NULL) {
        $this->reactor = $reactor;
        $this->writerFactory = $wf ?: new WriterFactory;
        $this->clients = new \SplObjectStorage;
        
        $this->canUsePeclHttp = (extension_loaded('http') && function_exists('http_parse_headers'));
        $this->allowedMethods = array_combine($this->allowedMethods, array_fill(0, count($this->allowedMethods), 1));
        
        $this->onHeadersMods = new \SplObjectStorage;
        $this->beforeResponseMods = new \SplObjectStorage;
        $this->afterResponseMods = new \SplObjectStorage;
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
        // @TODO We may want to change this in AMP so that sockets are non-blocking when they arrive
        stream_set_blocking($socket, FALSE);
        
        $client = new Client;
        $client->socket = $socket;
        
        $rawServerName = stream_socket_get_name($socket, FALSE);
        list($client->serverAddress, $client->serverPort) = $this->parseSocketName($rawServerName);
        
        $rawClientName = stream_socket_get_name($socket, TRUE);
        list($client->clientAddress, $client->clientPort) = $this->parseSocketName($rawClientName);
        
        $client->isEncrypted = isset(stream_context_get_options($socket)['ssl']);
        
        $client->parser = $this->canUsePeclHttp
            ? new PeclMessageParser(Parser::MODE_REQUEST)
            : new MessageParser(Parser::MODE_REQUEST);
        
        $onHeaders = function($requestArr) use ($client) {
            $this->afterRequestHeaders($client, $requestArr);
        };
        $onReadable = function($socket, $trigger) use ($client) {
            return ($trigger === Reactor::READ)
                ? $this->onReadableSocket($client)
                : $this->onReadableTimeout($client);
        };
        
        $client->parser->setOptions([
            'maxHeaderBytes' => $this->maxHeaderBytes,
            'maxBodyBytes' => $this->maxBodyBytes,
            'bodySwapSize' => $this->bodySwapSize,
            'beforeBody' => $onHeaders
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
    
    private function onReadableTimeout(Client $client) {
        if (empty($client->requests) || ltrim($client->parser->getBuffer(), "\r\n")) {
            $this->closeClient($client);
        }
    }
    
    private function onReadableSocket(Client $client) {
        $data = @fread($client->socket, $this->socketReadGranularity);
        
        if ($data || $data === '0') {
            $this->parseDataReadFromSocket($client, $data);
        } elseif (!is_resource($client->socket) || @feof($client->socket)) {
            $this->closeClient($client);
        }
    }
    
    private function parseDataReadFromSocket(Client $client, $data) {
        try {
            while ($requestArr = $client->parser->parse($data)) {
                $this->onRequest($client, $requestArr);
                $data = '';
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
            $asgiResponse = [Status::LENGTH_REQUIRED, Reason::HTTP_411, ['Connection' => 'close'], NULL];
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
        
        $client->requestCount += !isset($client->requests[$requestId]);
        
        $method = $requestArr['method'] = $this->normalizeMethodCase
            ? strtoupper($requestArr['method'])
            : $requestArr['method'];
        
        if ($host = $this->selectRequestHost($requestArr)) {
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
            'Content-Type' => 'text/html; charset=iso-8859-1',
            'Content-Length' => strlen($body),
            'Connection' => 'close'
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
        } else {
            $host = $this->selectDefaultHost();
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
    
    private function selectDefaultHost() {
        return $this->defaultHost ?: current($this->hosts);
    }
    
    private function generateAsgiEnv(Client $client, Host $host, array $requestArr) {
        $uri = $requestArr['uri'];
        if (!($uri === '/' || $uri === '*')) {
            $queryString = ($qPos = strpos($uri, '?')) ? substr($uri, $qPos +1) : '';
        } else {
            $queryString = '';
        }
        
        $scheme = $client->isEncrypted ? 'https' : 'http';
        $body = ($requestArr['body'] || $requestArr['body'] === '0') ? $requestArr['body'] : NULL;
        
        $serverName = $host->isWildcard() ? $client->clientAddress : $host->getName();
        
        $asgiEnv = [
            'ASGI_VERSION'      => '0.1',
            'ASGI_CAN_STREAM'   => TRUE,
            'ASGI_NON_BLOCKING' => TRUE,
            'ASGI_LAST_CHANCE'  => empty($requestArr['headersOnly']),
            'ASGI_ERROR'        => $this->errorStream,
            'ASGI_INPUT'        => $body,
            'ASGI_URL_SCHEME'   => $scheme,
            'AERYS_HOST_ID'     => $host->getId(),
            'SERVER_PORT'       => $client->serverPort,
            'SERVER_ADDR'       => $client->serverAddress,
            'SERVER_NAME'       => $serverName,
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
        if ($this->onHeadersMods->contains($host)) {
            foreach ($this->onHeadersMods->offsetGet($host) as $mod) {
                $mod->onHeaders($requestId);
            }
        }
    }
    
    private function onRequest(Client $client, array $requestArr) {
        if ($client->preBodyRequest) {
            return $this->finalizePreBodyRequest($client, $requestArr);
        }
        
        if (!$requestInitStruct = $this->initializeRequest($client, $requestArr)) {
            // initializeRequest() returns NULL if the server has already responded to the request
            return;
        }
        
        list($requestId, $asgiEnv, $host) = $requestInitStruct;
        
        $this->invokeOnHeadersMods($host, $requestId);
        
        if (!isset($client->responses[$requestId])) {
            // Mods may have modified the request environment, so reload it.
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
            $this->setResponse($requestId, [Status::INTERNAL_SERVER_ERROR, Reason::HTTP_500, [], $e]);
        }
    }
    
    private function onParseError(Client $client, ParseException $e) {
        $requestId = ++$this->lastRequestId;
        $this->requestIdClientMap[$requestId] = $client;
        
        $parsedMsgArr = $e->getParsedMsgArr();
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
        
        $client->requests[$requestId] = $asgiEnv;
        $client->requestHeaderTraces[$requestId] = $parsedMsgArr['trace'] ?: '?';
        $client->responses[$requestId] = $this->generateAsgiResponseFromParseException($e);
        
        $this->writePipelinedResponses($client);
    }
    
    private function generateAsgiResponseFromParseException(ParseException $e) {
        $status = $e->getCode() ?: Status::BAD_REQUEST;
        $reason = $this->getReasonPhrase($status);
        $msg = $e->getMessage();
        $body = "<html><body><h1>{$status} {$reason}</h1><p>{$msg}</p></body></html>";
        $headers = [
            'Date' => date(self::HTTP_DATE),
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Length' => strlen($body),
            'Connection' => 'close'
        ];
        
        return [$status, $reason, $headers, $body];
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
        
        if (!$this->insideBeforeResponseModLoop) {
            $host = $this->hosts[$asgiEnv['AERYS_HOST_ID']];
            $this->invokeBeforeResponseMods($host, $requestId);
            $this->writePipelinedResponses($client);
        }
    }
    
    private function writePipelinedResponses(Client $client) {
        foreach ($client->requests as $requestId => $asgiEnv) {
            if (isset($client->pipeline[$requestId])) {
                continue;
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
                    $msg .= "{$header}: {$nestedValue}\r\n";
                }
            } else {
                $msg .= "{$header}: {$value}\r\n";
            }
        }
        
        $msg .= "\r\n";
        
        return $msg;
    }
    
    private function write(Client $client) {
        try {
            foreach ($client->pipeline as $requestId => $responseWriter) {
                if (!$responseWriter) {
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
            && $this->defaultTextCharset
            && 0 === stripos($headers['CONTENT-TYPE'], 'text/')
            && !stristr($headers['CONTENT-TYPE'], 'charset=')
        ) {
            $headers['CONTENT-TYPE'] = $headers['CONTENT-TYPE'] . '; charset=' . $this->defaultTextCharset;
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
    
    private function invokeBeforeResponseMods(Host $host, $requestId) {
        if ($this->beforeResponseMods->contains($host)) {
            $this->insideBeforeResponseModLoop = TRUE;
            foreach ($this->beforeResponseMods->offsetGet($host) as $mod) {
                $mod->beforeResponse($requestId);
            }
            $this->insideBeforeResponseModLoop = FALSE;
        }
    }
    
    private function afterResponse(Client $client, $requestId) {
        $asgiEnv = $client->requests[$requestId];
        $asgiResponse = $client->responses[$requestId];
        
        $host = $this->hosts[$asgiEnv['AERYS_HOST_ID']];
        $this->invokeAfterResponseMods($host, $requestId);
        
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
    
    private function invokeAfterResponseMods(Host $host, $requestId) {
        if ($this->afterResponseMods->contains($host)) {
            $this->insideAfterResponseModLoop = TRUE;
            foreach ($this->afterResponseMods->offsetGet($host) as $mod) {
                $mod->afterResponse($requestId);
            }
            $this->insideAfterResponseModLoop = FALSE;
        }
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
    
    private function shouldCloseAfterResponse(array $asgiEnv, array $asgiResponse) {
        $responseHeaders = $asgiResponse[2];
        
        if (isset($responseHeaders['CONNECTION']) && !strcasecmp('close', $responseHeaders['CONNECTION'])) {
            $shouldClose = TRUE;
        } elseif (isset($asgiEnv['HTTP_CONNECTION']) && !strcasecmp('close', $asgiEnv['HTTP_CONNECTION'])) {
            $shouldClose = TRUE;
        } elseif ($asgiEnv['SERVER_PROTOCOL'] == '1.0' && !isset($asgiEnv['HTTP_CONNECTION'])) {
            $shouldClose = TRUE;
        } else {
            $shouldClose = FALSE;
        }
        
        return $shouldClose;
    }
    
    private function closeClient(Client $client) {
        $this->clearClientReferences($client);
        
        if ($this->socketSoLinger !== NULL) {
            $this->closeSocketWithSoLinger($client->socket, $client->isEncrypted);
        } else {
            @fclose($client->socket);
        }
    }
    
    private function closeSocketWithSoLinger($socket, $isEncrypted) {
        if ($isEncrypted) {
            @stream_socket_enable_crypto($socket, FALSE);
        }
        
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
    
    private function dequeueClientPipelineRequest($client, $requestId) {
        unset(
            $client->pipeline[$requestId],
            $client->requests[$requestId],
            $client->responses[$requestId],
            $client->requestHeaderTraces[$requestId],
            $this->requestIdClientMap[$requestId]
        );
        
        // Disable active onWritable stream subscriptions if the pipeline is no longer write-ready
        if ($client->writeSubscription && !current($client->pipeline)) {
            $client->writeSubscription->disable();
        }
    }
    
    function setRequest($requestId, array $asgiEnv) {
        if (isset($this->requestIdClientMap[$requestId])) {
            $client = $this->requestIdClientMap[$requestId];
        } else {
            throw new \DomainException(
                'Request ID does not exist: ' . $requestId
            );
        }
        
        $client->requests[$requestId] = $asgiEnv;
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
    
    private function setDefaultTextCharset($charset) {
        $this->defaultTextCharset = $charset;
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
                $onHeadersModArr = $this->onHeadersMods->contains($host)
                    ? $this->onHeadersMods->offsetGet($host)
                    : [];
                $onHeadersModArr[] = $mod;
                usort($onHeadersModArr, [$this, 'onHeadersModPrioritySort']);
                $this->onHeadersMods->attach($host, $onHeadersModArr);
            }
            
            if ($mod instanceof BeforeResponseMod) {
                $beforeResponseModArr = $this->beforeResponseMods->contains($host)
                    ? $this->beforeResponseMods->offsetGet($host)
                    : [];
                $beforeResponseModArr[] = $mod;
                usort($beforeResponseModArr, [$this, 'beforeResponseModPrioritySort']);
                $this->beforeResponseMods->attach($host, $beforeResponseModArr);
            }
            
            if ($mod instanceof AfterResponseMod) {
                $afterResponseModArr = $this->afterResponseMods->contains($host)
                    ? $this->afterResponseMods->offsetGet($host)
                    : [];
                $afterResponseModArr[] = $mod;
                usort($afterResponseModArr, [$this, 'afterResponseModPrioritySort']);
                $this->afterResponseMods->attach($host, $afterResponseModArr);
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

