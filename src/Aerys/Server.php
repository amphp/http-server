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
    private $cachedClientCount = 0;
    
    private $maxConnections = 0;
    private $maxRequestsPerSession = 100;
    private $idleConnectionTimeout = 15;
    private $maxStartLineSize = 2048;
    private $maxHeadersSize = 8192;
    private $maxEntityBodySize = 2097152;
    private $tempEntityDir = NULL;
    private $cryptoHandshakeTimeout = 5;
    private $defaultContentType = 'text/html';
    
    private $onHeadersMods = [];
    private $onRequestMods = [];
    private $beforeResponseMods = [];
    private $onResponseMods = [];
    
    private $isInsideBeforeResponseModLoop = FALSE;
    
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
                echo time(), ' (', $this->cachedClientCount, ")\n";
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
            
            if (!$serverSock = stream_socket_server($address, $errNo, $errStr, $flags, $context)) {
                throw new \ErrorException;
            }
            
            stream_set_blocking($serverSock, FALSE);
            
            $serverId = (int) $serverSock;
            $this->serverTlsMap[$serverId] = $tlsDefinition;
            $this->eventBase->onReadable($serverSock, function ($serverSock) {
                //if (!$this->maxConnections || ($this->cachedClientCount < $this->maxConnections)) {
                    $this->accept($serverSock);
                //}
            });
            
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
        while ($clientSock = @stream_socket_accept($serverSock, 0, $peerName)) {
            $serverId = (int) $serverSock;
            $sockName = stream_socket_get_name($serverSock, FALSE);
            stream_set_blocking($clientSock, FALSE);
            
            if ($tlsDefinition = $this->serverTlsMap[$serverId]) {
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
            } else {
                $this->generateClient($clientSock, $sockName, $peerName, FALSE);
            }
            
            ++$this->cachedClientCount;
        }
    }
    
    private function generateClient($clientSock, $sockName, $peerName, $isCryptoEnabled) {
        $clientId = (int) $clientSock;
        
        $writer = new Http\MessageWriter($clientSock);
        $parser = new Http\RequestParser;
        $parser->onHeaders(function(array $parsedRequest) use ($clientId) {
            $this->onHeaders($clientId, $parsedRequest);
        });
        $parser->onBodyData(function($data) use ($clientId) {
            $this->clients[$clientId]->tempEntityWriter->write($data);
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
            
            --$this->cachedClientCount;
        }
    }
    
    private function onReadable($clientSock, $triggeredBy) {
        $clientId = (int) $clientSock;
        
        if ($triggeredBy == EV_TIMEOUT) {
            return $this->handleReadTimeout($clientId);
        }
        
        $data = fread($clientSock, 8192);
        if (!$data && $data !== '0' && (!is_resource($clientSock) || feof($clientSock))) {
            return $this->close($clientId);
        }
        
        try {
            if ($parsedRequest = $this->clients[$clientId]->getParser()->parse($data)) {
                $this->onRequest($clientId, $parsedRequest);
            }
        } catch (Http\ParseException $e) {
            switch ($e->getCode()) {
                case Http\MessageParser::E_START_LINE_TOO_LARGE: $status = 414; break;
                case Http\MessageParser::E_HEADERS_TOO_LARGE: $status = 431; break;
                case Http\MessageParser::E_ENTITY_TOO_LARGE: $status = 413; break;
                default: $status = 400;
            }
            
            $this->doServerLayerError($client, $status, $e->getMessage());
        }
    }
    
    private function handleReadTimeout($clientId) {
        $client = $this->clients[$clientId];
        $parser = $client->getParser();
        if ($parser->isProcessing()) {
            $this->doServerLayerError($client, 408, 'Request timed out');
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
    
    private function onHeaders($clientId, array $parsedRequest) {
        $client = $this->clients[$clientId];
        
        try {
            $host = $this->selectRequestHost(
                $client->getServerIp(),
                $client->getServerPort(),
                $parsedRequest
            );
        } catch (Http\StatusException $e) {
            $this->doServerLayerError($client, $e->getCode(),  $e->getMessage(), $parsedRequest);
            return;
        }
        
        $requestId = ++$this->lastRequestId;
        
        $hostName = $host->getName();
        $asgiEnv = $this->generateAsgiEnv($client, $hostName, $parsedRequest);
        
        $tempEntityPath = tempnam($this->tempEntityDir, "aerys");
        $client->tempEntityWriter = new TempEntityWriter($tempEntityPath);
        
        $asgiEnv['ASGI_INPUT'] = $tempEntityPath;
        $asgiEnv['ASGI_LAST_CHANCE'] = FALSE;
        
        $client->pipeline[$requestId] = $asgiEnv;
        
        $hostId = $asgiEnv['SERVER_NAME'] . ':' . $client->getServerPort();
        if (isset($this->onHeadersMods[$hostId])) {
            foreach ($this->onHeadersMods[$hostId] as $mod) {
                $mod->onHeaders($clientId, $requestId);
            }
            
            // In case any Mods changed environment vars
            $asgiEnv = $client->pipeline[$requestId];
        }
        
        $handler = $host->getHandler();
        if ($asgiResponse = $handler($asgiEnv)) {
            return $this->setResponse($requestId, $asgiResponse);
        }
        
        // @TODO If `Expect: 100-continue` write HTTP/1.1 100 Continue to raw socket here
        
        $client->midRequestInfo = [$requestId, $host->getId(), $handler];
    }
    
    private function selectRequestHost($serverInterface, $serverPort, array $parsedRequest) {
        $headers = $parsedRequest['headers'];
        $protocol = $parsedRequest['protocol'];
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
    
    private function doServerLayerError(Client $client, $code, $msg, array $parsedRequest) {
        $requestId = ++$this->lastRequestId;
        $this->requestIdClientMap[$requestId] = $client->getId();
        
        if (!$parsedRequest) {
            $parsedRequest = [
                'method' => '?',
                'uri' => '?',
                'protocol' => '?',
                'headers' => []
            ];
        }
        
        // Generate a placeholder $asgiEnv
        $asgiEnv = $this->generateAsgiEnv($client, '?', $parsedRequest);
        $client->pipeline[$requestId] = $asgiEnv;
        
        $body = '<html><body><h1>'.$code.'</h1><hr /><p>'.$msg.'</p></body></html>';
        $headers = [
            'Date' => date(self::HTTP_DATE),
            'Content-Type' => 'text/html',
            'Content-Length' => strlen($body),
            'Connection' => 'close'
        ];
        
        $this->setResponse($requestId, [$code, $headers, $body]);
    }
    
    private function generateAsgiEnv(Client $client, $serverName, $parsedRequest) {
        $clientIp = $client->getIp();
        $clientPort = $client->getPort();
        $serverPort = $client->getServerPort();
        $isCryptoEnabled = $client->isCryptoEnabled();
        
        $method = $parsedRequest['method'];
        $uri = $parsedRequest['uri'];
        $protocol = $parsedRequest['protocol'];
        $headers = $parsedRequest['headers'];
        
        $asgiUrlScheme = $isCryptoEnabled ? 'https' : 'http';
        
        $queryString = '';
        $pathInfo = '';
        $scriptName = '';
        
        if ($uri == '/' || $uri == '*') {
            $queryString = '';
            $pathInfo = '';
            $scriptName = '';
        } elseif ($uri != '?') {
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
            'AWAITING_BODY'      => FALSE,
            
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
    
    private function onRequest($clientId, array $parsedRequest) {
        $client = $this->clients[$clientId];
        
        if (!$client->midRequestInfo) {
            $requestId = ++$this->lastRequestId;
            $this->requestIdClientMap[$requestId] = $clientId;
            
            try {
                $host = $this->selectRequestHost(
                    $client->getServerIp(),
                    $client->getServerPort(),
                    $parsedRequest
                );
            } catch (Http\StatusException $e) {
                $this->doServerLayerError($client, $e->getCode(),  $e->getMessage(), $parsedRequest);
                return;
            }
            
            $hostId = $host->getId();
            $handler = $host->getHandler();
            $client->pipeline[$requestId] = $this->generateAsgiEnv($client, $host->getName(), $parsedRequest);
        } else {
            list($requestId, $hostId, $handler) = $client->midRequestInfo;
            $client->midRequestInfo = NULL;
            $client->tempEntityWriter = NULL;
            $client->pipeline[$requestId]['AWAITING_BODY'] = FALSE;
            $client->pipeline[$requestId]['ASGI_LAST_CHANCE'] = TRUE;
        }
        
        ++$client->requestCount;
        
        if (isset($this->onRequestMods[$hostId])) {
            foreach ($this->onRequestMods[$hostId] as $mod) {
                $mod->onRequest($clientId, $requestId);
            }
        }
        
        // If a Mod assigned a response we're finished here
        if (isset($client->responses[$requestId])) {
            return;
        }
        
        if ($asgiResponse = $handler($client->pipeline[$requestId], $requestId)) {
            $this->setResponse($requestId, $asgiResponse);
        }
    }
    
    private function onResponse($clientId) {
        $client = $this->clients[$clientId];
        $requestId = key($client->pipeline);
        $asgiEnv = $client->pipeline[$requestId];
        
        // Determine if we need to close BEFORE any mods execute to prevent alterations. The 
        // response has already been sent and its original protocol and headers must be adhered to
        // regardless of whether a mod has altered the Connection header.
        if (isset($asgiEnv['HTTP_CONNECTION']) && !strcasecmp('close', $asgiEnv['HTTP_CONNECTION'])) {
            $shouldClose = TRUE;
        } elseif ($asgiEnv['SERVER_PROTOCOL'] == '1.1') {
            $shouldClose = FALSE;
        } else {
            $shouldClose = TRUE;
        }
        
        $hostId = strtolower($asgiEnv['SERVER_NAME']) . ':' . $client->getServerPort();
        if (isset($this->onResponseMods[$hostId])) {
            foreach ($this->onResponseMods[$hostId] as $mod) {
                $mod->onResponse($clientId, $requestId);
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
        
        --$this->cachedClientCount;
        
        return $client->getSocket();
    }
    
    function setResponse($requestId, array $asgiResponse) {
        if (!isset($this->requestIdClientMap[$requestId])) {
            throw new \DomainException;
        }
        
        $clientId = $this->requestIdClientMap[$requestId];
        $client = $this->clients[$clientId];
        
        if ($this->isInsideBeforeResponseModLoop) {
            $client->responses[$requestId] = $asgiResponse;
            return;
        }
        
        $asgiEnv = $client->pipeline[$requestId];
        $protocol = ($asgiEnv['SERVER_PROTOCOL'] != '?') ? $asgiEnv['SERVER_PROTOCOL'] : '1.0';
        
        if ($this->maxRequestsPerSession && $client->requestCount >= $this->maxRequestsPerSession) {
            $asgiResponse[1]['Connection'] = 'close';
        } elseif (!empty($asgiEnv['HTTP_CONNECTION'])
            && !strcasecmp($asgiEnv['HTTP_CONNECTION'], 'keep-alive')
        ) {
            if (empty($asgiResponse[1]['Connection'])) {
                $asgiResponse[1]['Connection'] = 'keep-alive';
            }
        }
        
        $asgiResponse[1] = $this->normalizeResponseHeaders($asgiResponse[1], $asgiResponse[2]);
        
        if ($asgiEnv['REQUEST_METHOD'] == 'HEAD') {
            $asgiResponse[2] = NULL;
        }
        
        $client->responses[$requestId] = $asgiResponse;
        
        $hostId = $asgiEnv['SERVER_NAME'] . ':' . $client->getServerPort();
        if (isset($this->beforeResponseMods[$hostId])) {
            $this->isInsideBeforeResponseModLoop = TRUE;
            
            foreach ($this->beforeResponseMods[$hostId] as $mod) {
               $mod->beforeResponse($clientId, $requestId);
            }
            
            // In case the response was altered by any mods ...
            $asgiResponse = $client->responses[$requestId];
            
            $this->isInsideBeforeResponseModLoop = FALSE;
        }
        
        list($status, $headers, $body) = $asgiResponse;
        
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
        
        // @TODO Can't enable this until the Http\MessageWriter supports auto-chunking:
        //
        //if ($hasBody && !(isset($keys['CONTENT-LENGTH']) || isset($keys['TRANSFER-ENCODING']))) {
        //    $headers['Transfer-Encoding'] = 'chunked';
        //}
        
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
    
    private function setCryptoHandshakeTimeout($seconds) {
        $this->cryptoHandshakeTimeout = (int) $seconds;
    }
    
    private function setDefaultContentType($mimeType) {
        $this->defaultContentType = $mimeType;
    }
    
    
    
    
    function registerMod($mod, $hostNameAndPort) {
        if ($this->isListening) {
            return;
        }
        
        $isWildcardMatch = ($hostNameAndPort == '*');
        
        foreach ($this->hosts as $host) {
            $hostId = $host->getId();
            
            if (!($isWildcardMatch || $hostId == $hostNameAndPort)) {
                continue;
            }
            
            if ($mod instanceof Mods\OnHeadersMod) {
                $this->onHeadersMods[$hostId][] = $mod;
            }
            
            if ($mod instanceof Mods\OnRequestMod) {
                $this->onHeadersMods[$hostId][] = $mod;
            }
            
            if ($mod instanceof Mods\BeforeResponseMod) {
                $this->beforeResponseMods[$hostId][] = $mod;
            }
            
            if ($mod instanceof Mods\OnResponseMod) {
                $this->onResponseMods[$hostId][] = $mod;
            }
        }
    }
}
























