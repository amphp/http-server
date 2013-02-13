<?php

namespace Aerys;

use Aerys\Engine\EventBase;

class Server {
    
    const SERVER_SOFTWARE = 'Aerys';
    const SERVER_VERSION = '0.0.1';
    const WILDCARD = '*';
    const WILDCARD_IPV4 = '0.0.0.0';
    const WILDCARD_IPV6 = '[::]';
    const MICROSECOND_RESOLUTION = 1000000;
    const HTTP_DATE = 'D, d M Y H:i:s T';
    
    private $isListening = FALSE;
    
    private $eventBase;
    private $vhosts;
    private $tlsDefinitions = [];
    private $clients = [];
    private $pendingTlsClients = [];
    private $clientIoSubscriptions = [];
    private $requestIdClientMap = [];
    private $tempEntityWriters = [];
    private $clientCount = 0;
    private $pendingTlsClientCount = 0;
    private $lastRequestId = 0;
    
    private $maxConnections = 0;
    private $maxRequestsPerSession = 100;
    private $idleConnectionTimeout = 30;
    private $maxStartLineSize = 2048;
    private $maxHeadersSize = 8192;
    private $maxEntityBodySize = 2097152;
    private $tempEntityDir = NULL;
    private $defaultContentType = 'text/html';
    private $autoReasonPhrase = TRUE;
    private $cryptoHandshakeTimeout = 3;
    private $ipv6Mode = FALSE;
    
    private $onRequestMods = [];
    private $beforeResponseMods = [];
    private $afterResponseMods = [];
    private $onCloseMods = [];
    
    private $insideBeforeResponseModLoop = FALSE;
    
    private $bodyWriterFactory;
    
    function __construct(EventBase $eventBase, VirtualHostGroup $vhosts) {
        $this->eventBase = $eventBase;
        $this->vhosts = $vhosts;
        $this->tempEntityDir = sys_get_temp_dir();
        
        $this->bodyWriterFactory = new Http\BodyWriters\BodyWriterFactory;
    }
    
    function listen() {
        if (!$this->isListening) {
            $this->isListening = TRUE;
            foreach ($this->bindServerSocks() as $boundAddress) {
                echo 'Server listening on ', $boundAddress, "\n";
            }
            $this->eventBase->repeat(1000000, function() {
                echo time(), ' (', $this->clientCount, ")\n";
            });
            $this->eventBase->run();
        }
    }
    
    function stop() {
        if ($this->isListening) {
            $this->eventBase->stop();
        }
    }
    
    private function bindServerSocks() {
        $boundSockets = [];
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        $wildcard = $this->ipv6Mode ? self::WILDCARD_IPV6 : self::WILDCARD_IPV4;
        
        foreach ($this->vhosts as $host) {
            $name = $host->getName();
            $port = $host->getPort();
            $interface = $host->getInterface();
            $interface = ($interface == '*') ? $wildcard : $interface;
            
            $bindOn = $interface . ':' . $port;
            $wildcardBindOn = $wildcard . ':' . $port;
            
            if (isset($boundSockets[$bindOn]) || isset($boundSockets[$wildcardBindOn])) {
                continue;
            }
            
            if ($serverSock = stream_socket_server($bindOn, $errNo, $errStr, $flags)) {
                stream_set_blocking($serverSock, FALSE);
                $this->eventBase->onReadable($serverSock, function($serverSock) {
                    $this->acceptNewClients($serverSock);
                });
                $boundSockets[$bindOn] = $host->getInterfaceId();
            } else {
                throw new \RuntimeException(
                    "Failed binding server on $bindOn: [Error# $errNo] $errStr"
                );
            }
        }
        
        return array_values($boundSockets);
    }
    
    private function acceptNewClients($serverSock) {
        $currentClientCount = $this->clientCount + $this->pendingTlsClientCount;
        if ($this->maxConnections && ($this->maxConnections <= $currentClientCount)) {
            return;
        }
        
        $serverName = stream_socket_get_name($serverSock, FALSE);
        $tlsWildcard = '*' . substr($serverName, strrpos($serverName, ':'));
        
        // Since we're going to gobble up sockets in a loop until there are none left we need to
        // suppress the E_WARNING that will trigger when the accept finally fails because there 
        // are no more clients awaiting acceptance.
        while ($clientSock = @stream_socket_accept($serverSock, 0, $clientName)) {
            if (isset($this->tlsDefinitions[$serverName])) {
                $tlsInterfaceId = $serverName;
            } elseif (isset($this->tlsDefinitions[$tlsWildcard])) {
                $tlsInterfaceId = $tlsWildcard;
            } else {
                $this->generateClient($clientSock, $clientName, $serverName);
                continue;
            }
            
            // If we're still here we need to enable crypto.
            ++$this->pendingTlsClientCount;
            
            $tlsDefinition = $this->tlsDefinitions[$tlsInterfaceId];
            $cryptoType = $tlsDefinition->getCryptoType();
            stream_context_set_option($clientSock, $tlsDefinition->getContextOptions());
            $subscription = $this->eventBase->onReadable($clientSock, function ($clientSock) {
                $this->enablePendingTlsClient($clientSock);
            }, 1000000);
            
            $pendingInfo = [$clientName, $serverName, $subscription, $cryptoType, time()];
            
            $clientId = (int) $clientSock;
            $this->pendingTlsClients[$clientId] = $pendingInfo;
            $this->enablePendingTlsClient($clientSock);
        }
    }
    
    private function enablePendingTlsClient($clientSock) {
        $clientId = (int) $clientSock;
        $pendingInfo = $this->pendingTlsClients[$clientId];
        
        list($clientName, $serverName, $subscription, $cryptoType, $connectedAt) = $pendingInfo;
        
        if ($cryptoResult = @stream_socket_enable_crypto($clientSock, TRUE, $cryptoType)) {
            --$this->pendingTlsClientCount;
            unset($this->pendingTlsClients[$clientId]);
            $subscription->cancel();
            
            $this->generateClient($clientSock, $clientName, $serverName);
            
        } elseif (FALSE === $cryptoResult || time() - $connectedAt > $this->cryptoHandshakeTimeout) {
            --$this->pendingTlsClientCount;
            unset($this->pendingTlsClients[$clientId]);
            $subscription->cancel();
            
            stream_socket_shutdown($clientSock, STREAM_SHUT_RDWR);
            fclose($clientSock);
        }
    }
    
    private function generateClient($clientSock, $clientName, $serverName) {
        $clientId = (int) $clientSock;
        
        $writer = new Http\MessageWriter($clientSock, $this->bodyWriterFactory);
        $parser = new Http\RequestParser;
        
        $parser->onHeaders(function(array $parsedRequest) use ($clientId) {
            $this->onRequest($clientId, $parsedRequest, $isAwaitingBody = TRUE);
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
        
        $this->clients[$clientId] = new Client($clientSock, $clientName, $serverName, $parser, $writer);
        $this->clientIoSubscriptions[$clientId] = [$readSub, $writeSub];
        
        ++$this->clientCount;
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
                $this->onRequest($clientId, $parsedRequest, $isAwaitingBody = FALSE);
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
            
            $client = $this->clients[$clientId];
            $this->doServerLayerError($client, $status, $e->getMessage());
        }
    }
    
    private function handleReadTimeout($clientId) {
        $client = $this->clients[$clientId];
        $parser = $client->getParser();
        $this->doServerLayerError($client, 408, 'Request timed out');
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
    
    private function onRequest($clientId, array $parsedRequest, $isAwaitingBody) {
        $client = $this->clients[$clientId];
        
        if ($preBodyRequestInfo = $client->shiftPreBodyRequestInfo()) {
            list($requestId, $host) = $preBodyRequestInfo;
            $client->pipeline[$requestId]['AWAITING_BODY'] = FALSE;
            $client->pipeline[$requestId]['ASGI_LAST_CHANCE'] = TRUE;
            $this->tempEntityWriters[$clientId] = NULL;
        } else {
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
            
            $client->pipeline[$requestId] = $this->generateAsgiEnv($client, $host->getName(), $parsedRequest);
        }
        
        if ($isAwaitingBody) {
            $tempEntityPath = tempnam($this->tempEntityDir, "aerys");
            $this->tempEntityWriters[$clientId] = new TempEntityWriter($tempEntityPath);
            $client->pipeline[$requestId]['ASGI_INPUT'] = $tempEntityPath;
            $client->pipeline[$requestId]['ASGI_LAST_CHANCE'] = FALSE;
            $client->storePreBodyRequestInfo([$requestId, $host]);
        } else {
            ++$client->requestCount;
        }
        
        $hostId = $host->getId();
        if (isset($this->onRequestMods[$hostId])) {
            foreach ($this->onRequestMods[$hostId] as $mod) {
                $mod->onRequest($clientId, $requestId);
                // If a Mod exported the socket or assigned a response we're finished
                if (!isset($this->clients[$clientId]) || isset($client->responses[$requestId])) {
                    return;
                }
            }
        }
        
        $handler = $host->getHandler();
        if ($asgiResponse = $handler($client->pipeline[$requestId], $requestId)) {
            $this->setResponse($requestId, $asgiResponse);
        } elseif ($isAwaitingBody
            && isset($asgiEnv['HTTP_EXPECT'])
            && strtoupper($asgiEnv['HTTP_EXPECT']) == '100-CONTINUE'
        ) {
            $msg = "100 Continue\r\n\r\n";
            $client->getWriter()->priorityWrite($msg);
        }
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
            return $this->vhosts->selectHost($host, $serverInterface, $serverPort);
        } else {
            throw new Http\StatusException(
                'HTTP Version not supported',
                505
            );
        }
    }
    
    private function doServerLayerError(Client $client, $code, $msg, array $parsedRequest = NULL) {
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
        
        $reason = '';
        $body = '<html><body><h1>'.$code.'</h1><hr /><p>'.$msg.'</p></body></html>';
        $headers = [
            'Date' => date(self::HTTP_DATE),
            'Content-Type' => 'text/html',
            'Content-Length' => strlen($body),
            'Connection' => 'close'
        ];
        
        $asgiResponse = [$code, $reason, $headers, $body];
        
        $this->setResponse($requestId, $asgiResponse);
    }
    
    private function generateAsgiEnv(Client $client, $serverName, $parsedRequest) {
        $uri = $parsedRequest['uri'];
        $headers = $parsedRequest['headers'];
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
        
        $scheme = isset(stream_context_get_options($client->getSocket())['ssl']) ? 'https' : 'http';
        
        $asgiEnv = [
            'SERVER_SOFTWARE'    => self::SERVER_SOFTWARE . ' ' . self::SERVER_VERSION,
            'SERVER_NAME'        => $serverName,
            'SERVER_PORT'        => $client->getServerPort(),
            'REMOTE_ADDR'        => $client->getIp(),
            'REMOTE_PORT'        => $client->getPort(),
            'SERVER_PROTOCOL'    => $parsedRequest['protocol'],
            'REQUEST_METHOD'     => $parsedRequest['method'],
            'REQUEST_URI'        => $uri,
            'QUERY_STRING'       => $queryString,
            'SCRIPT_NAME'        => $scriptName,
            'PATH_INFO'          => $pathInfo,
            'CONTENT_TYPE'       => $contentType,
            'CONTENT_LENGTH'     => $contentLength,
            'ASGI_VERSION'       => 0.1,
            'ASGI_URL_SCHEME'    => $scheme,// The URL scheme (will always be "http" unless mod.ssl enabled)
            'ASGI_INPUT'         => NULL,  // The temp filesystem path of the request entity body
            'ASGI_CAN_STREAM'    => TRUE,  // TRUE if the server supports callback-style delayed response and streaming traversable entity body.
            'ASGI_NON_BLOCKING'  => TRUE,  // TRUE if the server is calling the application in a non-blocking event loop.
            'ASGI_LAST_CHANCE'   => TRUE,  // TRUE if this is the final time a handler will be notified of the current request
            'ASGI_MULTIPROCESS'  => FALSE  // TRUE if an equivalent application object may be simultaneously invoked by another process, FALSE otherwise.
        ];
        
        foreach ($headers as $field => $value) {
            $field = 'HTTP_' . strtoupper(str_replace('-',  '_', $field));
            $asgiEnv[$field] = $value;
        }
        
        return $asgiEnv;
    }
    
    private function onResponse($clientId) {
        $client = $this->clients[$clientId];
        $requestId = key($client->pipeline);
        $asgiEnv = $client->pipeline[$requestId];
        
        // Determine if we need to close BEFORE any mods execute to prevent alterations. The 
        // response has already been sent and its original protocol and headers must be adhered to
        // regardless of whether a mod has altered the Connection header.
        $shouldClose = $this->shouldCloseAfterResponse($asgiEnv, $client->responses[$requestId]);
        
        $hostId = strtolower($asgiEnv['SERVER_NAME']) . ':' . $client->getServerPort();
        
        if (isset($this->afterResponseMods[$hostId])) {
            foreach ($this->afterResponseMods[$hostId] as $mod) {
                if (FALSE === $mod->afterResponse($clientId, $requestId)) {
                    break;
                }
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
        
        list($readSubscription, $writeSubscription) = $this->clientIoSubscriptions[$clientId];
        $readSubscription->cancel();
        $writeSubscription->cancel();
        
        unset(
            $this->clients[$clientId],
            $this->tempEntityWriters[$clientId],
            $this->clientIoSubscriptions[$clientId]
        );
        
        --$this->clientCount;
        
        return $client->getSocket();
    }
    
    function setRequest($requestId, array $asgiEnv) {
        if (!isset($this->requestIdClientMap[$requestId])) {
            //throw new \DomainException;
            return;
        }
        
        $clientId = $this->requestIdClientMap[$requestId];
        $client = $this->clients[$clientId];
        $client->pipeline[$requestId] = $asgiEnv;
    }
    
    function setResponse($requestId, array $asgiResponse) {
        if (!isset($this->requestIdClientMap[$requestId])) {
            //throw new \DomainException;
            return;
        }
        
        $clientId = $this->requestIdClientMap[$requestId];
        $client = $this->clients[$clientId];
        
        if ($this->insideBeforeResponseModLoop) {
            $client->responses[$requestId] = $asgiResponse;
            return;
        }
        
        if ($this->autoReasonPhrase && (string) $asgiResponse[1] === '') {
            $reasonConst = 'Aerys\\Http\\Reasons::HTTP_' . $asgiResponse[0];
            $asgiResponse[1] = defined($reasonConst) ? constant($reasonConst) : $asgiResponse[1];
        }
        
        $asgiEnv = $client->pipeline[$requestId];
        $protocol = $asgiEnv['SERVER_PROTOCOL'];
        $protocol = ($protocol == '1.0' || $protocol == '1.1') ? $protocol : '1.0';
        
        if ($this->maxRequestsPerSession && $client->requestCount >= $this->maxRequestsPerSession) {
            $asgiResponse[2]['Connection'] = 'close';
        } elseif (!empty($asgiEnv['HTTP_CONNECTION'])
            && !strcasecmp($asgiEnv['HTTP_CONNECTION'], 'keep-alive')
        ) {
            if (empty($asgiResponse[2]['Connection'])) {
                $asgiResponse[2]['Connection'] = 'keep-alive';
            }
        }
        
        $asgiResponse[2] = $this->normalizeResponseHeaders($protocol, $asgiResponse[2], $asgiResponse[3]);
        
        if ($asgiEnv['REQUEST_METHOD'] == 'HEAD') {
            $asgiResponse[3] = NULL;
        }
        
        $client->responses[$requestId] = $asgiResponse;
        
        $hostId = $asgiEnv['SERVER_NAME'] . ':' . $client->getServerPort();
        if (isset($this->beforeResponseMods[$hostId])) {
            $this->insideBeforeResponseModLoop = TRUE;
            foreach ($this->beforeResponseMods[$hostId] as $mod) {
                if (FALSE === $mod->beforeResponse($clientId, $requestId)) {
                    break;
                }
            }
            $this->insideBeforeResponseModLoop = FALSE;
            
            // In case the response was altered by any mods ...
            $asgiResponse = $client->responses[$requestId];
        }
        
        //list($status, $reason, $headers, $body) = $asgiResponse;
        //$msg = (new Http\Response)->setAll($protocol, $status, $reason, $headers, $body);
        
        $writer = $client->getWriter();
        //$writer->enqueue($msg);
        $writer->enqueue($protocol, $asgiResponse);
    }
    
    private function normalizeResponseHeaders($protocol, array $headers, $body) {
        $headers = array_combine(array_map('strtoupper', array_keys($headers)), $headers);
        
        if (!isset($headers['DATE'])) {
            $headers['DATE'] = date(self::HTTP_DATE);
        }
        
        $hasBody = ($body || $body === '0');
        
        if ($hasBody && !isset($headers['CONTENT-TYPE'])) {
            $headers['CONTENT-TYPE'] = $this->defaultContentType;
        }
        
        $hasContentLength = isset($headers['CONTENT_LENGTH']);
        $isChunked = isset($headers['TRANSFER-ENCODING']) && !strcasecmp($headers['TRANSFER-ENCODING'], 'chunked');
        $isIterator = $body instanceof \Iterator;
        
        if ($hasBody && !$hasContentLength && is_string($body)) {
            $headers['CONTENT-LENGTH'] = strlen($body);
        } elseif ($hasBody && !$hasContentLength && is_resource($body)) {
            fseek($body, 0, SEEK_END);
            $headers['CONTENT-LENGTH'] = ftell($body);
            rewind($body);
        } elseif ($hasBody && $protocol >= 1.1 && !$isChunked && $isIterator) {
            $headers['TRANSFER-ENCODING'] = 'chunked';
        } elseif ($hasBody && !$hasContentLength && $protocol < '1.1' && $isIterator) {
            $headers['CONNECTION'] = 'close';
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
    
    private function setCryptoHandshakeTimeout($seconds) {
        $this->cryptoHandshakeTimeout = (int) $seconds;
    }
    
    private function setIpv6Mode($boolFlag) {
        $this->ipv6Mode = (bool) $boolFlag;
    }
    
    
    
    function setTlsDefinition($interfaceId, TlsDefinition $tlsDef) {
        // PHP returns the server name WITHOUT brackets when retrieved from IPv6 server sockets. If 
        // we don't remove them here the server won't realize it needs to encrypt the relevant
        // traffic.
        $interfaceId = str_replace(['[', ']'], '', $interfaceId);
        
        $this->tlsDefinitions[$interfaceId] = $tlsDef;
    }
    
    function registerMod($hostId, $mod) {
        if ($mod instanceof Mods\OnRequestMod) {
            $this->onRequestMods[$hostId][] = $mod;
        }
        
        if ($mod instanceof Mods\BeforeResponseMod) {
            $this->beforeResponseMods[$hostId][] = $mod;
        }
        
        if ($mod instanceof Mods\AfterResponseMod) {
            $this->afterResponseMods[$hostId][] = $mod;
        }
        
        if ($mod instanceof Mods\OnCloseMod) {
            $this->onCloseMods[$hostId][] = $mod;
        }
    }
    
}
























