<?php

namespace Aerys;

use Amp\Reactor,
    Amp\TcpServer,
    Aerys\Parsing\ParseException,
    Aerys\Parsing\ResourceReadException,
    Aerys\Mods\OnHeadersMod,
    Aerys\Mods\BeforeResponseMod,
    Aerys\Mods\AfterResponseMod;

class Server extends TcpServer {
    
    const SERVER_SOFTWARE = 'Aerys/0.0.1';
    const HTTP_DATE = 'D, d M Y H:i:s T';
    
    private $pipelineFactory;
    private $pipelines;
    private $writablePipelines;
    private $autoWriteInterval = 0.04;
    private $hosts = [];
    private $requestIdPipelineMap = [];
    private $onHeadersMods = [];
    private $beforeResponseMods = [];
    private $afterResponseMods = [];
    private $insideBeforeResponseModLoop = FALSE;
    private $insideAfterResponseModLoop = FALSE;
    
    private $logErrorsTo = 'php://stderr';
    private $maxConnections = 2500;
    private $maxRequests = 150;
    private $keepAliveTimeout = 95;
    private $defaultContentType = 'text/html';
    private $defaultCharset = 'utf-8';
    private $autoReasonPhrase = TRUE;
    private $sendServerToken = FALSE;
    private $disableKeepAlive = FALSE;
    private $socketSoLinger = NULL;
    private $defaultHost;
    private $normalizeMethodCase = TRUE;
    private $requireBodyLength = FALSE;
    private $allowedMethods = [
        Method::GET     => 1,
        Method::HEAD    => 1,
        Method::OPTIONS => 1,
        Method::TRACE   => 1,
        Method::PUT     => 1,
        Method::POST    => 1,
        Method::DELETE  => 1
    ];
    
    private $errorStream = STDERR;
    private $lastRequestId = 0;
    private $cachedClientCount = 0;
    
    function __construct(Reactor $reactor, PipelineFactory $pf = NULL) {
        $this->reactor = $reactor;
        $this->pipelineFactory = $pf ?: new PipelineFactory;
        $this->pipelines = new \SplObjectStorage;
        $this->writablePipelines = new \SplObjectStorage;
    }
    
    function addHost(Host $host) {
        $hostId = $host->getId();
        $this->hosts[$hostId] = $host;
    }
    
    function start() {
        if ($this->canStart()) {
            $this->errorStream = fopen($this->logErrorsTo, 'ab+');
            $this->reactor->repeat(function() { $this->autoWrite(); }, $this->autoWriteInterval);
            
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
        $pipeline = $this->pipelineFactory->makePipeline($socket);
        
        $readSubscription = $this->reactor->onReadable($socket, function($socket, $trigger) use ($pipeline) {
            $this->onReadable($pipeline, $trigger);
        }, $this->keepAliveTimeout);
        
        $this->pipelines->attach($pipeline, [$readSubscription, $socket]);
    }
    
    private function onReadable(Pipeline $pipeline, $trigger) {
        return ($trigger === Reactor::TIMEOUT) ? $this->timeout($pipeline) : $this->read($pipeline);
    }
    
    private function timeout($pipeline) {
        if ($pipeline->isParseInProgress() || !$pipeline->hasRequestsAwaitingResponse()) {
            $this->closePipeline($pipeline);
        }
    }
    
    private function read(Pipeline $pipeline) {
        try {
            while ($requestArr = $pipeline->read()) {
                return $requestArr['headersOnly']
                    ? $this->onPreBodyHeaders($pipeline, $requestArr)
                    : $this->onRequest($pipeline, $requestArr);
            }
        } catch (ParseException $e) {
            $this->onParseError($pipeline, $e->getCode());
        } catch (ResourceReadException $e) {
            $this->closePipeline($pipeline);
        }
    }
    
    private function onPreBodyHeaders(Pipeline $pipeline, array $requestArr) {
        if (!$requestInitArr = $this->initializeRequest($pipeline, $requestArr)) {
            return;
        }
        
        list($requestId, $asgiEnv, $host) = $requestInitArr;
        
        if ($this->requireBodyLength && empty($asgiEnv['CONTENT_LENGTH'])) {
            $this->setResponse($requestId, [Status::LENGTH_REQUIRED, Reason::HTTP_411, [], NULL]);
            return;
        }
        
        $hasExpectHeader = !empty($asgiEnv['HTTP_EXPECT']);
        $needsContinue = $hasExpectHeader && !strcasecmp($asgiEnv['HTTP_EXPECT'], '100-continue');
        $pipeline->setPreBodyRequest($requestId, $asgiEnv, $requestArr['trace'], $host, $needsContinue);
        $this->invokeOnHeadersMods($host->getId(), $requestId);
        
        if ($needsContinue && !$pipeline->hasResponse($requestId)) {
            $this->setResponse($requestId, [Status::CONTINUE_100, Reason::HTTP_100, [], NULL]);
        } else {
            $this->read($pipeline);
        }
    }
    
    private function initializeRequest(Pipeline $pipeline, array $requestArr) {
        $requestId = ++$this->lastRequestId;
        $this->requestIdPipelineMap[$requestId] = $pipeline;
        
        $requestArr['method'] = $this->normalizeMethodCase
            ? strtoupper($requestArr['method'])
            : $requestArr['method'];
        
        $method = $requestArr['method'];
        
        if (!$host = $this->selectRequestHost($requestArr)) {
            $asgiEnv = $this->generateAsgiEnv($pipeline, $serverName = '?', $requestArr);
            $pipeline->setRequest($requestId, $asgiEnv, $requestArr['trace']);
            $response = $this->generateInvalidHostNameResponse();
            return NULL;
        }
        
        $asgiEnv = $this->generateAsgiEnv($pipeline, $host->getName(), $requestArr);
        $pipeline->setRequest($requestId, $asgiEnv, $requestArr['trace']);
        
        if (!isset($this->allowedMethods[$method])) {
            $response = $this->generateMethodNotAllowedResponse();
        } elseif ($method === Method::TRACE) {
            $response = $this->generateTraceResponse($requestArr['trace']);
        } elseif ($method === Method::OPTIONS && $requestArr['uri'] === '*') {
            $response = $this->generateOptionsResponse();
        } else {
            $response = NULL;
        }
        
        if ($response) {
            $pipeline->setResponse($requestId, $response);
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
        $headers = $requestArr['headers'];
        $hostHeader = isset($headers['HOST']) ? strtolower($headers['HOST']) : NULL;
        
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
     * @TODO Determine how best to allow for forward proxies specifying absolute URIs
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
    
    private function generateAsgiEnv(Pipeline $pipeline, $serverName, $requestArr) {
        $uri = $requestArr['uri'];
        $queryString =  ($uri == '/' || $uri == '*') ? '' : parse_url($uri, PHP_URL_QUERY);
        $scheme = $pipeline->isEncrypted() ? 'https' : 'http';
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
            'SERVER_PORT'       => $pipeline->getPort(),
            'SERVER_PROTOCOL'   => $requestArr['protocol'],
            'REMOTE_ADDR'       => $pipeline->getPeerAddress(),
            'REMOTE_PORT'       => $pipeline->getPeerPort(),
            'REQUEST_METHOD'    => $requestArr['method'],
            'REQUEST_URI'       => $uri,
            'QUERY_STRING'      => $queryString
        ];
        
        if ($headers = $requestArr['headers']) {
            $headers = array_change_key_case($headers, CASE_UPPER);
        }
        
        if (isset($headers['CONTENT-TYPE'])) {
            $asgiEnv['CONTENT_TYPE'] = $headers['CONTENT-TYPE'];
            unset($headers['CONTENT-TYPE']);
        }
        
        if (isset($headers['CONTENT-LENGTH'])) {
            $asgiEnv['CONTENT_LENGTH'] = $headers['CONTENT-LENGTH'];
            unset($headers['CONTENT-LENGTH']);
        }
        
        foreach ($headers as $field => $value) {
            $field = 'HTTP_' . str_replace('-', '_', $field);
            $value = ($value === (array) $value) ? implode(',', $value) : $value;
            $asgiEnv[$field] = $value;
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
    
    private function onRequest(Pipeline $pipeline, array $requestArr) {
        if ($pipeline->hasPreBodyRequest()) {
            return $this->finalizePreBodyRequest($pipeline, $requestArr);
        } elseif (!$requestInitArr = $this->initializeRequest($pipeline, $requestArr)) {
            return;
        }
        
        list($requestId, $asgiEnv, $host) = $requestInitArr;
        
        $this->invokeOnHeadersMods($host->getId(), $requestId);
        
        if (!$pipeline->hasResponse($requestId)) {
            $this->invokeRequestHandler($requestId, $asgiEnv, $host->getHandler());
        }
    }
    
    private function finalizePreBodyRequest(Pipeline $pipeline, $requestArr) {
        list($requestId, $asgiEnv, $host, $needsNewRequestId) = $pipeline->shiftPreBodyRequest();
        
        if ($needsNewRequestId) {
            $requestId = ++$this->lastRequestId;
            $this->requestIdPipelineMap[$requestId] = $pipeline;
        }
        
        if ($hasTrailer = !empty($asgiEnv['HTTP_TRAILER'])) {
            $asgiEnv = $this->generateAsgiEnv($pipeline, $host->getName(), $requestArr);
        }
        
        if ($needsNewRequestId || $hasTrailer) {
            $pipeline->setRequest($requestId, $asgiEnv, $requestArr['trace']);
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
    
    private function onParseError(Pipeline $pipeline, $status) {
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
        $this->requestIdPipelineMap[$requestId] = $pipeline;
        $asgiEnv = $this->generateAsgiEnv($pipeline, $serverName = '?', $requestArr);
        $pipeline->setRequest($requestId, $asgiEnv, '?');
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
        
        if (!isset($this->requestIdPipelineMap[$requestId])) {
            return;
        }
        
        $pipeline = $this->requestIdPipelineMap[$requestId];
        $asgiEnv = $pipeline->getRequest($requestId);
        $asgiResponse = $this->normalizeResponse($asgiEnv, $asgiResponse);
        
        if ($this->disableKeepAlive
            || ($this->maxRequests && $pipeline->getRequestCount() >= $this->maxRequests)
        ) {
            $asgiResponse[2]['CONNECTION'] = 'close';
        }
        
        $pipeline->setResponse($requestId, $asgiResponse);
        
        // The second isset() check for the $requestId's existence is not an accident. Third-party
        // code may have exported the socket while executing mods and if so, we shouldn't proceed.
        // DON'T REMOVE THE ISSET CHECK BELOW!
        if (!$this->insideBeforeResponseModLoop && isset($this->requestIdPipelineMap[$requestId])) {
            $hostId = $asgiEnv['SERVER_NAME'] . ':' . $asgiEnv['SERVER_PORT'];
            $this->invokeBeforeResponseMods($hostId, $requestId);
            $pipeline->enqueueResponsesForWrite();
            $this->write($pipeline);
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
    
    private function autoWrite() {
        foreach ($this->writablePipelines as $pipeline) {
            $this->write($pipeline);
        }
    }
    
    private function write(Pipeline $pipeline) {
        if ($completedRequestId = $pipeline->write()) {
            $this->afterResponse($pipeline, $completedRequestId);
        } else {
            $this->writablePipelines->attach($pipeline);
        }
    }
    
    private function afterResponse(Pipeline $pipeline, $requestId) {
        $asgiEnv = $pipeline->getRequest($requestId);
        $asgiResponse = $pipeline->getResponse($requestId);
        
        $hostId = $asgiEnv['SERVER_NAME'] . ':' . $asgiEnv['SERVER_PORT'];
        $this->invokeAfterResponseMods($hostId, $requestId);
        
        if ($asgiResponse[0] == Status::SWITCHING_PROTOCOLS) {
            $this->upgradeConnection($pipeline, $asgiResponse[4], $asgiEnv);
        } elseif ($this->shouldCloseAfterResponse($asgiEnv, $asgiResponse)) {
            $this->closePipeline($pipeline);
        } else {
            $this->finalizeKeepAliveResponse($pipeline, $requestId);
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
    
    private function upgradeConnection($pipeline, callable $callback, array $asgiEnv) {
        $socket = $this->clearPipeline($pipeline);
        $callback($socket, $asgiEnv);
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
    
    private function finalizeKeepAliveResponse($pipeline, $requestId) {
        $pipeline->clearRequest($requestId);
        
        if ($pipeline->canWrite()) {
            $this->writablePipelines->attach($pipeline);
        } else {
            $this->writablePipelines->detach($pipeline);
        }
        
        unset($this->requestIdPipelineMap[$requestId]);
    }
    
    private function clearPipeline(Pipeline $pipeline) {
        if ($requestIds = $pipeline->getRequestIds()) {
            foreach ($requestIds as $requestId) {
                unset($this->requestIdPipelineMap[$requestId]);
            }
        }
        
        list($readSubscription, $socket) = $this->pipelines->offsetGet($pipeline);
        $readSubscription->cancel();
        $this->pipelines->detach($pipeline);
        $this->writablePipelines->detach($pipeline);
        
        if ($this->cachedClientCount-- === $this->maxConnections) {
            $this->resume();
        }
        
        return $socket;
    }
    
    private function closePipeline(Pipeline $pipeline) {
        $socket = $this->clearPipeline($pipeline);
        if (is_resource($socket)) {
            $this->closeSocket($socket);
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
        if (isset($this->requestIdPipelineMap[$requestId])) {
            $pipeline = $this->requestIdPipelineMap[$requestId];
        } else {
            throw new \DomainException(
                'Request ID does not exist: ' . $requestId
            );
        }
        
        return $pipeline->getRequest($requestId);
    }
    
    function getResponse($requestId) {
        if (isset($this->requestIdPipelineMap[$requestId])) {
            $pipeline = $this->requestIdPipelineMap[$requestId];
        } else {
            throw new \DomainException(
                'Request ID does not exist: ' . $requestId
            );
        }
        
        return $pipeline->getResponse($requestId);
    }
    
    function getTrace($requestId) {
        if (isset($this->requestIdPipelineMap[$requestId])) {
            $pipeline = $this->requestIdPipelineMap[$requestId];
        } else {
            throw new \DomainException(
                'Request ID does not exist: ' . $requestId
            );
        }
        
        return $pipeline->getTrace($requestId);
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
    
    private function setMaxHeaderSize($bytes) {
        $this->pipelineFactory->setParserMaxHeaderBytes($bytes);
    }
    
    private function setMaxBodySize($bytes) {
        $this->pipelineFactory->setParserMaxBodyBytes($bytes);
    }
    
    private function setBodySwapSize($bytes) {
        $this->pipelineFactory->setParserBodySwapSize($bytes);
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
        $this->allowedMethods[Method::GET] = 1;
        $this->allowedMethods[Method::HEAD] = 1;
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

