<?php

namespace Aerys;

class Server {
    
    const SERVER_SOFTWARE = 'Aerys';
    const SERVER_VERSION = '0.0.1';
    
    const MICROSECOND_RESOLUTION = 1000000;
    const HTTP_DATE = 'D, d M Y H:i:s T';
    
    private $isListening = FALSE;
    
    private $eventBase;
    private $readSubscriptions = [];
    private $writeSubscriptions = [];
    
    private $hosts;
    private $tlsDefinitions = [];
    private $serverTlsMap = [];
    
    private $clients = [];
    private $requestIdClientMap = [];
    private $preRequestInfoMap = [];
    private $tempEntityWriters = [];
    
    private $lastRequestId = 0;
    private $cachedConnectionCount = 0;
    
    private $maxSimultaneousConnections = 0;
    private $maxRequestsPerSession = 100;
    private $idleConnectionTimeout = 15;
    private $maxStartLineSize = 2048;
    private $maxHeadersSize = 8192;
    private $maxEntityBodySize = 2097152;
    private $tempEntityDir = NULL;
    private $cryptoHandshakeTimeout = 5;
    private $defaultContentType = 'text/html';
    private $exposeServerToken = TRUE;
    
    private $onHeadersMods = [];
    private $onRequestMods = [];
    private $beforeResponseMods = [];
    private $onResponseMods = [];
    
    function __construct(Engine\EventBase $eventBase, HostCollection $hosts, $tlsDefs = NULL) {
        $this->eventBase = $eventBase;
        $this->hosts = $hosts;
        if ($tlsDefs) {
            $this->setTlsDefinitions($tlsDefs);
        }
        
        $this->tempEntityDir = sys_get_temp_dir();
    }
    
    private function setTlsDefinitions($tlsDefs) {
        if (!(is_array($tlsDefs) || $tlsDefs instanceof \Traversable)) {
            throw new \InvalidArgumentException;
        }
        
        foreach ($tlsDefs as $tlsDef) {
            if ($tlsDef instanceof TlsDefinition) {
                $address = $tlsDef->getAddress();
                $this->tlsDefinitions[$address] = $tlsDef;
            } else {
                throw new \InvalidArgumentException;
            }
        }
    }
    
    function listen() {
        if (!$this->isListening) {
        
            $this->isListening = TRUE;
            
            foreach ($this->initializeServerSocks() as $boundAddress) {
                echo 'Server listening on ', $boundAddress, "\n";
            }
            
            $this->eventBase->repeat(1000000, function() {
                echo time(), ' (', $this->cachedConnectionCount, ")\n";
            });
            
            $this->eventBase->run();
        }
    }
    
    function stop() {
        if ($this->isListening) {
            $this->eventBase->stop();
        }
    }
    
    private function initializeServerSocks() {
        $boundServers = [];
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        
        foreach ($this->hosts as $host) {
            
            $name = $host->getName();
            $interface = $host->getInterface();
            $port = $host->getPort();
            $address = $interface . ':' . $port;
            $wildcardAddress = Host::NIC_WILDCARD . ':' . $port;
            
            // An interface:port combo only needs to be bound once
            if (isset($boundServers[$address]) || isset($boundServers[$wildcardAddress])) {
                continue;
            }
            
            if ($tlsDefinition = $this->getTlsDefinition($interface, $port)) {
                $context = $tlsDefinition->getStreamContext();
            } else {
                $context = stream_context_create();
            }
            
            if ($serverSock = stream_socket_server($address, $errNo, $errStr, $flags, $context)) {
                stream_set_blocking($serverSock, FALSE);
                
                $serverId = (int) $serverSock;
                $this->serverTlsMap[$serverId] = $tlsDefinition;
                $this->eventBase->onReadable($serverSock, function ($serverSock) {
                    $this->accept($serverSock);
                });
                
            } else {
                throw new \ErrorException();
            }
            
            $boundServers[$address] = TRUE;
        }
        
        return array_map(function($addr) { return str_replace(Host::NIC_WILDCARD, '*', $addr); }, array_keys($boundServers));
    }
    
    private function getTlsDefinition($interface, $port) {
        $wildcardMatch =  Host::NIC_WILDCARD . ':' . $port;
        $addressMatch = $interface . ':' . $port;
        
        if (isset($this->tlsDefinitions[$wildcardMatch])) {
            return $this->tlsDefinitions[$wildcardMatch];
        } elseif (isset($this->tlsDefinitions[$addressMatch])) {
            return $this->tlsDefinitions[$addressMatch];
        } else {
            return NULL;
        }
    }
    
    private function accept($serverSock) {
        if ($this->maxSimultaneousConnections
            && ($this->cachedConnectionCount >= $this->maxSimultaneousConnections)
        ) {
            return;
        }
        
        while ($clientSock = @stream_socket_accept($serverSock, 0, $peerName)) {
            $serverId = (int) $serverSock;
            $sockName = stream_socket_get_name($serverSock, FALSE);
            stream_set_blocking($clientSock, FALSE);
            
            if (!$tlsDefinition = $this->serverTlsMap[$serverId]) {
                $this->generateClient($clientSock, $sockName, $peerName, FALSE);
            } else {
                $subscription = $this->eventBase->onReadable($clientSock, function ($clientSock) {
                    $this->enablePendingCrypto($clientSock);
                }, 1000000);
                
                $clientId = (int) $clientSock;
                $this->clientsPendingCrypto[$clientId] = [
                     $tlsDefinition->getCryptoType(),
                    time(),
                    $subscription,
                    $sockName,
                    $peerName,
                ];
                
                $this->enablePendingCrypto($clientSock);
            }
            
            ++$this->cachedConnectionCount;
        }
    }
    
    private function generateClient($clientSock, $sockName, $peerName, $isCryptoEnabled) {
        $clientId = (int) $clientSock;
        
        $writer = new Http\MessageWriter($clientSock);
        $parser = new Http\RequestParser;
        $parser->onHeaders(function(array $parsedRequestArr) use ($clientId) {
            $this->onHeaders($clientId, $parsedRequestArr);
        });
        $parser->onBodyData(function($data) use ($clientId) {
            $this->tempEntityWriters[$clientId]->write($data);
        });
        
        $readSub = $this->eventBase->onReadable($clientSock, function ($clientSock, $triggeredBy) {
            $this->onReadable($clientSock, $triggeredBy);
        }, $this->idleConnectionTimeout * self::MICROSECOND_RESOLUTION);
    
        $writeSub = $this->eventBase->onWritable($clientSock, function ($clientSock, $triggeredBy) {
            $this->onWritable($clientSock, $triggeredBy);
        });
        
        $this->readSubscriptions[$clientId] = $readSub;
        $this->writeSubscriptions[$clientId] = $writeSub;
        
        list($serverIp, $serverPort) = explode(':', $sockName);
        list($clientIp, $clientPort) = explode(':', $peerName);
        
        $this->clients[$clientId] = new Client(
            $clientSock,
            $clientIp,
            $clientPort,
            $serverIp,
            $serverPort,
            $parser,
            $writer,
            $isCryptoEnabled
        );
    }
    
    private function enablePendingCrypto($clientSock) {
        $clientId = (int) $clientSock;
        $pendingInfoArr = $this->clientsPendingCrypto[$clientId];
        list($cryptoType, $connectedAt, $subscription, $sockName, $peerName) = $pendingInfoArr;
        
        if ($isComplete = @stream_socket_enable_crypto($clientSock, TRUE, $cryptoType)) {
            
            $subscription->cancel();
            unset($this->clientsPendingCrypto[$clientId]);
            $this->generateClient($clientSock, $sockName, $peerName, TRUE);
            
        } elseif ($isComplete === FALSE || (time() - $connectedAt > $this->cryptoHandshakeTimeout)) {
            
            $subscription->cancel();
            unset($this->clientsPendingCrypto[$clientId]);
            stream_socket_shutdown($clientSock, STREAM_SHUT_RDWR);
            fclose($clientSock);
            
            --$this->cachedConnectionCount;
        }
    }
    
    private function onReadable($clientSock, $triggeredBy) {
        $clientId = (int) $clientSock;
        
        if ($triggeredBy == EV_TIMEOUT) {
            return $this->handleReadTimeout($clientId);
        }
        
        $data = fread($clientSock, 8192);
        if (!$data && $data !== '0' && (!is_resource($clientSock) || feof($clientSock))) {
            $this->close($clientId);
            return;
        }
        
        $client = $this->clients[$clientId];
        $requestParser = $client->getParser();
        
        try {
            if ($parsedRequestArr = $requestParser->parse($data)) {
                $this->onRequest($clientId, $parsedRequestArr);
            }
        } catch (Http\ParseException $e) {
            switch ($e->getCode()) {
                case Http\MessageParser::E_START_LINE_TOO_LARGE:
                    $status = 414;
                    break;
                case Http\MessageParser::E_HEADERS_TOO_LARGE:
                    $status = 431;
                    break;
                case Http\MessageParser::E_ENTITY_TOO_LARGE:
                    $status = 413;
                    break;
                default:
                    $status = 400;
            }
            
            $requestId = NULL;
            $hasAsgiEnv = FALSE;
            $this->doServerLayerError($client, $status, $e->getMessage(), $requestId, $hasAsgiEnv);
        }
    }
    
    private function handleReadTimeout($clientId) {
        if ($this->clients[$clientId]->getParser()->isProcessing()) {
            $this->doServerLayerError($client, 408, 'Request timed out', NULL, FALSE);
        } else {
            $this->close($clientId);
        }
    }
    
    private function onWritable($clientSock) {
        $clientId = (int) $clientSock;
        
        try {
            if ($this->clients[$clientId]->getWriter()->write()) {
                $this->onResponse($clientId);
            }
        } catch (ResourceException $e) {
            $this->close($clientId);
        }
    }
    
    private function onHeaders($clientId, array $parsedRequestArr) {
        $requestId = ++$this->lastRequestId;
        $client = $this->clients[$clientId];
        $this->requestIdClientMap[$requestId] = $clientId;
        
        try {
            $host = $this->selectRequestHost(
                $client->getServerIp(),
                $client->getServerPort(),
                $parsedRequestArr
            );
        } catch (Http\StatusException $e) {
            return $this->doServerLayerError(
                $client,
                $e->getCode(),
                $e->getMessage(),
                $requestId,
                FALSE
            );
        }
        
        $hostName = $host->getName();
        $asgiEnv = $this->generateAsgiRequestVars($client, $hostName, $parsedRequestArr);
        
        $tempEntityPath = tempnam($this->tempEntityDir, "aerys");
        $this->tempEntityWriters[$clientId] = new TempEntityWriter($tempEntityPath);
        $asgiEnv['ASGI_INPUT'] = $tempEntityPath;
        $client->pipeline[$requestId] = $asgiEnv;
        
        $this->preRequestInfoMap[$clientId] = array($requestId, $host);
        
        if (!empty($this->onHeadersMods[$hostName])) {
            foreach ($this->onHeadersMods[$hostName] as $callableMod) {
                $callableMod($clientId, $requestId);
            }
        }
    }
    
    private function selectRequestHost($serverInterface, $serverPort, array $parsedRequestArr) {
        $headers = $parsedRequestArr['headers'];
        $protocol = $parsedRequestArr['protocol'];
        $host = isset($headers['HOST']) ? $headers['HOST'] : NULL;
        
        if (NULL === $host && $protocol >= '1.1') {
            throw new Http\StatusException(
                'HTTP/1.1 requests must specify a Host: header',
                400
            );
        } elseif ($protocol === '1.1' || $protocol === '1.0') {
            return $this->hosts->selectHost($host, $serverInterface, $serverPort);
        } else {
            throw new Http\StatusException(
                'HTTP Version not supported',
                505
            );
        }
    }
    
    private function doServerLayerError(Client $client, $code, $msg, $requestId, $hasAsgiEnv) {
        if (!$requestId) {
            $requestId = ++$this->lastRequestId;
            $this->requestIdClientMap[$requestId] = $client->getId();
        }
        
        if (!$hasAsgiEnv) {
            $serverName = '';
            $requestParser = $client->getParser();
            $parsedRequestArr = [
                'method'    => $requestParser->getMethod(),
                'uri'       => $requestParser->getUri(),
                'protocol'  => $requestParser->getProtocol(),
                'headers'   => $requestParser->getHeaders()
            ];
            
            $asgiEnv = $this->generateAsgiRequestVars($client, $serverName, $parsedRequestArr);
            $client->pipeline[$requestId] = $asgiEnv;
        } else {
            $asgiEnv = $client->pipeline[$requestId];
        }
        
        $asgiResponse = $this->generateServerLayerErrorResponse($code, $msg, $asgiEnv);
        
        $this->setResponse($requestId, $asgiResponse);
        $client->closeAfter = $requestId;
    }
    
    private function generateServerLayerErrorResponse($code, $msg, $asgiEnv) {
        $serverToken = $this->exposeServerToken
            ? self::SERVER_SOFTWARE . ' ' . self::SERVER_VERSION
            : '';
            
        $body = '<html><body><h1>'.$code.'</h1><p>'.$msg.'</p><hr/>'.$serverToken.'</body></html>';
        $headers = [
            'Date' => date(self::HTTP_DATE),
            'Content-Type' => 'text/html',
            'Content-Length' => strlen($body)
        ];
        
        return [$code, $headers, $body];
    }
    
    private function generateAsgiRequestVars(Client $client, $serverName, $parsedRequestArr) {
        $serverPort = $client->getServerPort();
        $clientIp = $client->getIp();
        $clientPort = $client->getPort();
        $isCryptoEnabled = $client->isCryptoEnabled();
        
        $method = $parsedRequestArr['method'];
        $uri = $parsedRequestArr['uri'];
        $protocol = $parsedRequestArr['protocol'];
        $headers = $parsedRequestArr['headers'];
        
        $asgiUrlScheme = $isCryptoEnabled ? 'https' : 'http';
        
        $queryString = '';
        $pathInfo = '';
        $scriptName = '';
        
        if ($uri == '/' || $uri == '*') {
            $queryString = '';
            $pathInfo = '';
            $scriptName = '';
        } else {
            $uriParts = parse_url($uri);
            $queryString = isset($uriParts['query']) ? $uriParts['query'] : '';
            $decodedPath = rawurldecode($uriParts['path']);
            $pathParts = pathinfo($decodedPath);
            $pathInfo = !$uri || ($pathParts['dirname'] == '/') ? '' : $pathParts['dirname'];
            $scriptName = '/' . $pathParts['filename'];
            $scriptName = isset($pathParts['extension']) ? $scriptName . '.' . $pathParts['extension'] : $scriptName;
        }
        
        $contentType = isset($headers['CONTENT-TYPE']) ? $headers['CONTENT-TYPE'] : '';
        $contentLength = isset($headers['CONTENT-LENGTH']) ? $headers['CONTENT-LENGTH'] : '';
        unset($headers['CONTENT-TYPE'], $headers['CONTENT-LENGTH']);
        
        $asgiEnv = [
            'SERVER_SOFTWARE'    => self::SERVER_SOFTWARE . ' ' . self::SERVER_VERSION,
            'SERVER_NAME'        => $serverName,
            'SERVER_PORT'        => $serverPort,
            'REMOTE_ADDR'        => $clientIp,
            'REMOTE_PORT'        => $clientPort,
            'SERVER_PROTOCOL'    => $protocol,
            'REQUEST_METHOD'     => $method,
            'REQUEST_URI'        => $uri,
            'QUERY_STRING'       => $queryString,
            'SCRIPT_NAME'        => $scriptName,
            'PATH_INFO'          => $pathInfo,
            'CONTENT_TYPE'       => $contentType,
            'CONTENT_LENGTH'     => $contentLength,
            
            // NON-STANDARD AERYS ENV VARS
            'REQUEST_TIME'       => time(),
            'REQUEST_TIME_FLOAT' => microtime(TRUE),
            
            // ADDITIONAL ASGI-REQUIRED ENV VARS
            'ASGI_VERSION'       => 0.1,
            'ASGI_URL_SCHEME'    => $asgiUrlScheme,// The URL scheme
            'ASGI_INPUT'         => NULL,  // The temp filesystem path of the request entity body
            'ASGI_CAN_STREAM'    => TRUE,  // ATRUE if the server supports callback style delayed response and streaming writer object.
            'ASGI_NON_BLOCKING'  => TRUE,  // TRUE if the server is calling the application in a non-blocking event loop.
            'ASGI_LAST_CHANCE'   => TRUE,  // TRUE if the server expects (but does not guarantee!) that the application will only be invoked this one time during the life of its containing process.
            'ASGI_MULTIPROCESS'  => FALSE  // This is a boolean value, which MUST be TRUE if an equivalent application object may be simultaneously invoked by another process, FALSE otherwise.
        ];
        
        foreach ($headers as $field => $value) {
            $field = 'HTTP_' . strtoupper(str_replace('-',  '_', $field));
            $asgiEnv[$field] = $value;
        }
        
        return $asgiEnv;
    }
    
    private function onRequest($clientId, array $parsedRequestArr) {
        $client = $this->clients[$clientId];
        ++$client->requestCount;
        
        if (isset($this->preRequestInfoMap[$clientId])) {
            list($requestId, $host) = $this->preRequestInfoMap[$clientId];
            unset(
                $this->preRequestInfoMap[$clientId],
                $this->tempEntityWriters[$clientId]
            );
            $asgiEnv = $client->pipeline[$requestId];
            $hostName = $host->getName();
        } else {
            $requestId = ++$this->lastRequestId;
            $this->requestIdClientMap[$requestId] = $clientId;
            $serverIp = $client->getServerIp();
            $serverPort = $client->getServerPort();
            $host = $this->selectRequestHost($serverIp, $serverPort, $parsedRequestArr);
            $hostName = $host->getName();
            $client->pipeline[$requestId] = $this->generateAsgiRequestVars($client, $hostName, $parsedRequestArr);
        }
        
        if (!empty($this->onRequestMods[$hostName])) {
            foreach ($this->onRequestMods[$hostName] as $callableMod) {
                $callableMod($clientId, $requestId);
            }
        }
        
        // If an onHeaders/onRequest mod already assigned a response we're finished
        if (isset($client->responses[$requestId])) {
            return;
        }
        
        // onHeaders/onRequest mods may have modified the ENV, so we make sure we fetch those changes
        $asgiEnv = $client->pipeline[$requestId];
        
        $handler = $host->getHandler();
        if ($asgiResponse = $handler($asgiEnv, $requestId)) {
            $this->setResponse($requestId, $asgiResponse);
        }
    }
    
    private function onResponse($clientId) {
        $client = $this->clients[$clientId];
        $requestId = key($client->pipeline);
        $asgiEnv = $client->pipeline[$requestId];
        
        // Determine if we need to close BEFORE any mods execute to prevent alterations. The 
        // response has already been sent and its original Connection: header must be adhered to
        // regardless of whether a mod has altered it.
        $shouldClose = ($client->closeAfter == $requestId || $this->shouldCloseOnResponse($asgiEnv));
        
        $hostName = $asgiEnv['SERVER_NAME'];
        if (!empty($this->onResponseMods[$hostName])) {
            foreach ($this->onResponseMods[$hostName] as $callableMod) {
                $callableMod($clientId, $requestId);
            }
        }
        
        if ($shouldClose) {
            $this->close($clientId);
        } else {
            unset(
                $this->requestIdClientMap[$requestId],
                $client->pipeline[$requestId],
                $client->responses[$requestId]
            );
        }
    }
    
    private function shouldCloseOnResponse(array $asgiEnv) {
        $shouldClose = FALSE;
        
        switch ($asgiEnv['SERVER_PROTOCOL']) {
            case '1.1':
                if (isset($asgiEnv['HTTP_CONNECTION']) && !strcasecmp('close', $asgiEnv['HTTP_CONNECTION'])) {
                    $shouldClose = TRUE;
                }
                break;
            case '1.0';
                if (!isset($asgiEnv['HTTP_CONNECTION']) || strcasecmp('keep-alive', $asgiEnv['HTTP_CONNECTION'])) {
                    $shouldClose = TRUE;
                }
                break;
            default:
                $shouldClose = TRUE;
                
        }
        
        return $shouldClose;
    }
    
    private function close($clientId) {
        $clientSock = $this->export($clientId);
        stream_socket_shutdown($clientSock, STREAM_SHUT_WR);
        
        while (TRUE) {
            $read = fgets($clientSock);
            if ($read === FALSE || $read === '') {
                break;
            }
        }
        
        fclose($clientSock);
    }
    
    function export($clientId) {
        if (!isset($this->clients[$clientId])) {
            throw new \DomainException(
                'Invalid client ID: ' . $clientId
            );
        }
        
        $client = $this->clients[$clientId];
        
        if ($pipeline = $this->clients[$clientId]->pipeline) {
            foreach (array_keys($pipeline) as $requestId) {
                unset($this->requestIdClientMap[$requestId]);
            }
        }
        
        $readSub = $this->readSubscriptions[$clientId];
        $readSub->cancel();
        $writeSub = $this->writeSubscriptions[$clientId];
        $writeSub->cancel();
        
        unset(
            $this->clients[$clientId],
            $this->preRequestInfoMap[$clientId],
            $this->tempEntityWriters[$clientId],
            $this->readSubscriptions[$clientId],
            $this->writeSubscriptions[$clientId]
        );
        
        --$this->cachedConnectionCount;
        
        return $client->getSocket();
    }
    
    function setResponse($requestId, array $asgiResponse) {
        if (!isset($this->requestIdClientMap[$requestId])) {
            throw new \DomainException;
        }
        
        $clientId = $this->requestIdClientMap[$requestId];
        $client = $this->clients[$clientId];
        
        $asgiEnv = $client->pipeline[$requestId];
        $protocol = $asgiEnv['SERVER_PROTOCOL'];
        
        if ($this->maxRequestsPerSession && $client->getRequestCount() >= $this->maxRequestsPerSession) {
            $asgiResponse[1]['Connection'] = 'close';
        } elseif (!empty($asgiEnv['HTTP_CONNECTION'])
            && !strcasecmp($asgiEnv['HTTP_CONNECTION'], 'keep-alive')
        ) {
            if (empty($asgiResponse[1]['Connection'])) {
                $asgiResponse[1]['Connection'] = 'keep-alive';
            }
        }
        
        if ($asgiEnv['REQUEST_METHOD'] == 'HEAD') {
            $asgiResponse[2] = NULL;
        }
        
        $client->responses[$requestId] = $asgiResponse;
        
        $hostName = $asgiEnv['SERVER_NAME'];
        if (!empty($this->beforeResponseMods[$hostName])) {
            foreach ($this->beforeResponseMods[$hostName] as $callableMod) {
                $callableMod($clientId, $requestId);
            }
            
            // In case the response was altered by any mods ...
            $asgiResponse = $client->responses[$requestId];
        }
        
        list($status, $headers, $body) = $asgiResponse;
        
        $headers = $this->normalizeResponseHeaders($headers, $body);
        
        $msg = (new Http\Response)->setAll($protocol, $status, NULL, $headers, $body);
        $writer = $client->getWriter();
        $writer->enqueue($msg);
    }
    
    private function normalizeResponseHeaders(array $headers, $body) {
        $headers = array_combine(array_map('strtoupper', array_keys($headers)), $headers);
        
        if (!isset($headers['DATE'])) {
            $headers['DATE'] = date(self::HTTP_DATE);
        }
        
        $hasBody = ($body || $body === '0');
        
        if ($hasBody && !isset($headers['CONTENT-TYPE'])) {
            $headers['CONTENT-TYPE'] = $this->defaultContentType;
        }
        
        if ($hasBody && !isset($headers['CONTENT_LENGTH']) && is_string($body)) {
            $headers['CONTENT-LENGTH'] = strlen($body);
        }
        
        // @todo Can't enable this until the Http\MessageWriter supports auto-chunking:
        //
        //if ($hasBody && !(isset($keys['CONTENT-LENGTH']) || isset($keys['TRANSFER-ENCODING']))) {
        //    $headers['Transfer-Encoding'] = 'chunked';
        //}
        
        if ($this->exposeServerToken) {
            $headers['SERVER'] = self::SERVER_SOFTWARE . '/' . self::SERVER_VERSION;
        } else {
            unset($headers['SERVER']);
        }
        
        return $headers;
    }
    
    function getResponse($requestId) {
        if (!isset($this->requestIdClientMap[$requestId])) {
            throw new \DomainException;
        }
        
        $clientId = $this->requestIdClientMap[$requestId];
        $client = $this->clients[$clientId];
        
        if (isset($client->responses[$requestId])) {
            return $client->responses[$requestId];
        } else {
            throw new \DomainException;
        }
    }
    
    function getRequest($requestId) {
        if (!isset($this->requestIdClientMap[$requestId])) {
            throw new \DomainException;
        }
        
        $clientId = $this->requestIdClientMap[$requestId];
        $client = $this->clients[$clientId];
        
        if (isset($client->pipeline[$requestId])) {
            return $client->pipeline[$requestId];
        } else {
            throw new \DomainException;
        }
    }
    
    function setOption($option, $value) {
        $setter = 'set' . ucfirst($option);
        if (property_exists($this, $option) && method_exists($this, $setter)) {
            $this->$setter($value);
        } else {
            throw new \DomainException($option);
        }
    }
    
    private function setMaxSimultaneousConnections($maxConns) {
        $this->maxSimultaneousConnections = (int) $maxConns;
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
    
    private function setCryptoHandshakeTimeout($seconds) {
        $this->cryptoHandshakeTimeout = (int) $seconds;
    }
    
    private function setDefaultContentType($mimeType) {
        $this->defaultContentType = $mimeType;
    }
    
    private function setExposeServerToken($boolFlag) {
        $this->exposeServerToken = (bool) $boolFlag;
    }
    
}

