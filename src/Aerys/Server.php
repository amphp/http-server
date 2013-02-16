<?php

namespace Aerys;

use Aerys\Engine\EventBase,
    Aerys\Http\TempEntityWriter,
    Aerys\Http\BodyWriters\BodyWriterFactory;

class Server {
    
    const SERVER_SOFTWARE = 'Aerys';
    const SERVER_VERSION = '0.0.1';
    const WILDCARD = '*';
    const WILDCARD_IPV4 = '0.0.0.0';
    const WILDCARD_IPV6 = '[::]';
    const MICROSECOND_TICKS = 1000000;
    const HTTP_DATE = 'D, d M Y H:i:s T';
    
    const E_LOG_HANDLER = 'User Handler';
    const E_LOG_ON_REQUEST = 'OnRequest Mod';
    const E_LOG_BEFORE_RESPONSE = 'BeforeResponse Mod';
    const E_LOG_AFTER_RESPONSE = 'AfterResponse Mod';
    const E_LOG_UPGRADE_CALLBACK = 'Upgrade Callback';
    
    private $isListening = FALSE;
    
    private $errorStream = STDERR;
    
    private $eventBase;
    private $virtualHosts;
    private $bodyWriterFactory;
    private $tlsDefinitions = [];
    private $clients = [];
    private $pendingTlsClients = [];
    private $clientIoSubscriptions = [];
    private $requestIdClientMap = [];
    private $pendingTlsClientCount = 0;
    private $clientCount = 0;
    private $lastRequestId = 0;
    
    private $maxConnections = 0;
    private $maxRequestsPerSession = 100;
    private $idleConnectionTimeout = 5;
    private $maxStartLineSize = 2048;
    private $maxHeadersSize = 8192;
    private $maxEntityBodySize = 2097152;
    private $tempEntityDir = NULL;
    private $defaultContentType = 'text/html';
    private $autoReasonPhrase = TRUE;
    private $cryptoHandshakeTimeout = 3;
    private $ipv6Mode = FALSE;
    private $handleAfterHeaders = FALSE;
    private $errorLogFile;
    
    private $onRequestMods = [];
    private $beforeResponseMods = [];
    private $afterResponseMods = [];
    private $onCloseMods = [];
    
    private $insideBeforeResponseModLoop = FALSE;
    
    function __construct(
        EventBase $eventBase,
        VirtualHostGroup $virtualHosts,
        BodyWriterFactory $bodyWriterFactory = NULL
    ) {
        $this->eventBase = $eventBase;
        $this->virtualHosts = $virtualHosts;
        $this->bodyWriterFactory = $bodyWriterFactory ?: new BodyWriterFactory;
        
        $this->tempEntityDir = sys_get_temp_dir();
    }
    
    function stop() {
        if (!$this->isListening) {
            return;
        }
        $this->eventBase->stop();
        
        if ($this->errorLogFile) {
            fclose($this->errorStream);
        }
    }
    
    function listen() {
        if (!$this->isListening) {
            $this->isListening = TRUE;
            
            if ($this->errorLogFile) {
                $this->errorStream = fopen($this->errorLogFile, 'ab+');
            }
            
            foreach ($this->bindServerSocks() as $boundAddress) {
                echo 'Server listening on ', $boundAddress, "\n";
            }
            
            $this->eventBase->repeat(1000000, function() {
                echo time(), ' (', $this->clientCount, ")\n";
            });
            
            $this->eventBase->run();
        }
    }
    
    function getErrorStream() {
        return $this->errorStream;
    }
    
    private function logUserlandError(\Exception $e, $thrownByConst, $host, $requestUri) {
        fwrite(
            $this->errorStream,
            '------------------------------------' . PHP_EOL .
            'Exception thrown by ' . $thrownByConst . PHP_EOL .
            'When: ' . date(self::HTTP_DATE) . PHP_EOL .
            'Host: ' . $host . PHP_EOL .
            'Request URI: ' . $requestUri .  PHP_EOL .
            $e . PHP_EOL .
            '------------------------------------' . PHP_EOL
        );
    }
    
    private function bindServerSocks() {
        $boundSockets = [];
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        $wildcard = $this->ipv6Mode ? self::WILDCARD_IPV6 : self::WILDCARD_IPV4;
        
        foreach ($this->virtualHosts as $host) {
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
        
        $parser = new Http\RequestParser;
        $writer = new Http\MessageWriter($clientSock, $this->bodyWriterFactory);
        $client = new Client($clientSock, $clientName, $serverName, $parser, $writer);
        
        $parser->onHeaders(function(array $parsedRequest) use ($client) {
            $this->onHeaders($client, $parsedRequest);
        });
        $parser->onBodyData(function($data) use ($client) {
            return $client->writeTempEntityData($data);
        });
        
        $readSub = $this->eventBase->onReadable($clientSock, function ($clientSock, $triggeredBy) use ($client, $parser) {
            $this->onReadable($clientSock, $triggeredBy, $client, $parser);
        }, $this->idleConnectionTimeout * self::MICROSECOND_TICKS);
        
        $writeSub = $this->eventBase->onWritable($clientSock, function () use ($client, $writer) {
            try {
                if ($writer->write()) {
                    $this->onResponse($client);
                }
            } catch (ResourceException $e) {
                $this->close($client);
            }
        });
        
        $this->clients[$clientId] = $client;
        $this->clientIoSubscriptions[$clientId] = [$readSub, $writeSub];
        
        ++$this->clientCount;
    }
    
    private function onReadable($clientSock, $triggeredBy, Client $client, Http\RequestParser $parser) {
        if ($triggeredBy == EV_TIMEOUT) {
            return $this->handleReadTimeout($client);
        }
        
        $data = fread($clientSock, 8192);
        if (!$data && $data !== '0' && (!is_resource($clientSock) || feof($clientSock))) {
            return $this->close($client);
        }
        
        try {
            if ($parsedRequest = $parser->parse($data)) {
                $this->onRequest($client, $parsedRequest);
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
            
            $this->doServerLayerError($client, $status, $e->getMessage());
        }
    }
    
    private function handleReadTimeout(Client $client) {
        return $client->hasStartedParsingRequest()
            ? $this->doServerLayerError($client, 408, 'Request timed out')
            : $this->close($client);
    }
    
    private function onHeaders(Client $client, array $parsedRequest) {
        $requestId = ++$this->lastRequestId;
        $this->requestIdClientMap[$requestId] = $client;
        
        list($asgiEnv, $host) = $this->generateNewRequest($client, $parsedRequest);
        
        $tempEntityPath = tempnam($this->tempEntityDir, "aerys");
        $tempEntityWriter = new TempEntityWriter($tempEntityPath);
        $client->setTempEntityWriter($tempEntityWriter);
        
        $asgiEnv['ASGI_INPUT'] = $tempEntityWriter->getResource();
        $asgiEnv['ASGI_LAST_CHANCE'] = FALSE;
        
        $needs100Continue = (isset($asgiEnv['HTTP_EXPECT'])
            && !strcasecmp($asgiEnv['HTTP_EXPECT'], '100-continue')
        );
        
        $client->storePreBodyRequest($requestId, $asgiEnv, $host, $needs100Continue);
        
        if ($this->invokeOnRequestMods($client, $host->getId(), $requestId)) {
            return;
        } elseif ($this->handleAfterHeaders
            && !$this->invokeRequestHandler($requestId, $asgiEnv, $host->getHandler())
            && $needs100Continue
        ) {
            $this->setResponse($requestId, [100, 'Continue', [], NULL]);
        } elseif (!$this->handleAfterHeaders && $needs100Continue) {
            $this->setResponse($requestId, [100, 'Continue', [], NULL]);
        }
    }
    
    private function generateNewRequest(Client $client, array $parsedRequest) {
        $serverIp = $client->getServerIp();
        $serverPort = $client->getServerPort();
        $host = $this->selectRequestHost($serverIp, $serverPort, $parsedRequest);
        $asgiEnv = $this->generateAsgiEnv($client, $host->getName(), $parsedRequest);
        
        return [$asgiEnv, $host];
    }
    
    /**
     * @TODO Move Host: header verification into the RequestParser
     * @TODO Move Protocol verification into RequestParser
     */
    private function selectRequestHost($serverIp, $serverPort, array $parsedRequest) {
        $headers = $parsedRequest['headers'];
        $protocol = $parsedRequest['protocol'];
        $host = isset($headers['HOST']) ? $headers['HOST'] : NULL;
        
        if (NULL === $host && $protocol >= '1.1') {
            throw new Http\StatusException(
                'HTTP/1.1 requests must specify a Host: header',
                400
            );
        } elseif ($protocol === '1.1' || $protocol === '1.0') {
            return $this->virtualHosts->selectHost($host, $serverIp, $serverPort);
        } else {
            throw new Http\StatusException(
                'HTTP Version not supported',
                505
            );
        }
    }
    
    /**
     * @return bool Returns TRUE if mod(s) assigned a response; FALSE otherwise.
     */
    private function invokeOnRequestMods(Client $client, $hostId, $requestId) {
        if (empty($this->onRequestMods[$hostId])) {
            return FALSE;
        }
        
        foreach ($this->onRequestMods[$hostId] as $mod) {
            try {
                $mod->onRequest($this, $requestId);
            } catch (\Exception $e) {
                $asgiEnv = $this->getRequest($requestId);
                $thrownBy = self::E_LOG_ON_REQUEST;
                $serverName = $asgiEnv['SERVER_NAME'];
                $requestUri = $asgiEnv['REQUEST_URI'];
                
                $this->logUserlandError($e, $thrownBy, $serverName, $requestUri);
            }
        }
        
        return $client->hasResponse($requestId);
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
            $thrownBy = self::E_LOG_HANDLER;
            $serverName = $asgiEnv['SERVER_NAME'];
            $requestUri = $asgiEnv['REQUEST_URI'];
            
            $this->logUserlandError($e, $thrownBy, $serverName, $requestUri);
            $this->setResponse($requestId, [500, 'Internal Server Error', [], NULL]);
            
            return TRUE;
        }
    }
    
    private function doServerLayerError(Client $client, $code, $msg, array $parsedRequest = NULL) {
        $requestId = ++$this->lastRequestId;
        $this->requestIdClientMap[$requestId] = $client;
        
        $parsedRequest = $parsedRequest ?: [
            'method' => '?',
            'uri' => '?',
            'protocol' => '1.0',
            'headers' => []
        ];
        
        // Generate a placeholder $asgiEnv for our "fake" $requestId
        $asgiEnv = $this->generateAsgiEnv($client, '?', $parsedRequest);
        $client->addRequestToPipeline($requestId, $asgiEnv);
        
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
            'ASGI_URL_SCHEME'    => $scheme,
            'ASGI_INPUT'         => NULL,
            'ASGI_ERROR'         => $this->errorStream,
            'ASGI_CAN_STREAM'    => TRUE,
            'ASGI_NON_BLOCKING'  => TRUE,
            'ASGI_LAST_CHANCE'   => TRUE
        ];
        
        foreach ($headers as $field => $value) {
            $field = 'HTTP_' . strtoupper(str_replace('-',  '_', $field));
            $asgiEnv[$field] = $value;
        }
        
        return $asgiEnv;
    }
    
    private function onRequest($client, array $parsedRequest) {
        
        $hasPreBodyRequest = $client->hasPreBodyRequest();
        
        if ($hasPreBodyRequest) {
            list($requestId, $asgiEnv, $host, $needsNewRequestId) = $client->shiftPreBodyRequest();
            $asgiEnv['ASGI_LAST_CHANCE'] = TRUE;
            rewind($asgiEnv['ASGI_INPUT']);
        } else {
            list($asgiEnv, $host) = $this->generateNewRequest($client, $parsedRequest);
            $needsNewRequestId = FALSE;
            $requestId = ++$this->lastRequestId;
            $this->requestIdClientMap[$requestId] = $client;
        }
        
        if ($needsNewRequestId) {
            $requestId = ++$this->lastRequestId;
            $this->requestIdClientMap[$requestId] = $client;
        }
        
        // If a Trailer: header exists we need to regenerate $asgiEnv because it may have changed
        if ($hasPreBodyRequest && !empty($asgiEnv['HTTP_TRAILER'])) {
            $asgiEnv = $this->generateAsgiEnv($client, $host->getName(), $parsedRequest);
        }
        
        $client->addRequestToPipeline($requestId, $asgiEnv);
        
        // We only need to invoke the handler if onRequest mods didn't assign a response
        if (!$this->invokeOnRequestMods($client, $host->getId(), $requestId)) {
            $this->invokeRequestHandler($requestId, $asgiEnv, $host->getHandler());
        }
    }
    
    private function onResponse(Client $client) {
        list($requestId, $asgiEnv, $asgiResponse) = $client->getPipelineFront();
        
        // Determine if we need to close BEFORE any mods execute to prevent alterations. The 
        // response has already been sent and its original protocol and headers must be adhered to
        // regardless of whether a mod has altered the Connection header.
        $shouldClose = $this->shouldCloseAfterResponse($asgiEnv, $asgiResponse);
        
        $hostId = $asgiEnv['SERVER_NAME'] . ':' . $client->getServerPort();
        
        if (isset($this->afterResponseMods[$hostId])) {
            foreach ($this->afterResponseMods[$hostId] as $mod) {
                try {
                    $mod->afterResponse($this, $requestId);
                } catch (\Exception $e) {
                    $thrownBy = self::E_LOG_AFTER_RESPONSE;
                    $serverName = $asgiEnv['SERVER_NAME'];
                    $requestUri = $asgiEnv['REQUEST_URI'];
                    
                    $this->logUserlandError($e, $thrownBy, $serverName, $requestUri);
                }
            }
        }
        
        if ($asgiResponse[0] == 101 && ($upgradeCallback = $asgiResponse[4])) {
            $clientSock = $this->export($client);
            
            try {
                $upgradeCallback($clientSock);
            } catch (\Exception $e) {
                $thrownBy = self::E_LOG_UPGRADE_CALLBACK;
                $serverName = $asgiEnv['SERVER_NAME'];
                $requestUri = $asgiEnv['REQUEST_URI'];
                
                $this->logUserlandError($e, $thrownBy, $serverName, $requestUri);
            }
        } elseif ($shouldClose) {
            $this->close($client);
        } else {
            $client->shiftPipelineFront();
            unset($this->requestIdClientMap[$requestId]);
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
    
    /**
     * Attempt a graceful socket close by shutting down writes and trying to read any remaining
     * data still on the wire before finally closing the socket. The final read is limited to a
     * predetermined number of bytes to avoid slowness. If there's any more data after this read
     * we'll just have to cut it off ungracefully.
     */
    private function close(Client $client) {
        $clientSock = $this->export($client);
        stream_socket_shutdown($clientSock, STREAM_SHUT_WR);
        
        // Despite `stream_get_meta_data` reporting that blocking is FALSE at this point, the call
        // to stream_socket_shutdown covertly places the stream back in blocking mode. If we don't
        // manually remind PHP that this is supposed to be a non-blocking stream the subsequent
        // fread operation can potentially block for several seconds. Manually resetting the socket
        // to non-blocking fixes this bug.
        stream_set_blocking($clientSock, FALSE);
        
        fread($clientSock, 8192);
        fclose($clientSock);
    }
    
    private function export(Client $client) {
        if ($requestIds = $client->getPipelineRequestIds()) {
            foreach ($requestIds as $requestId) {
                unset($this->requestIdClientMap[$requestId]);
            }
        }
        
        $clientId = $client->getId();
        list($readSubscription, $writeSubscription) = $this->clientIoSubscriptions[$clientId];
        $readSubscription->cancel();
        $writeSubscription->cancel();
        
        unset(
            $this->clients[$clientId],
            $this->clientIoSubscriptions[$clientId]
        );
        
        --$this->clientCount;
        
        return $client->getSocket();
    }
    
    function setResponse($requestId, array $asgiResponse) {
        if (!isset($this->requestIdClientMap[$requestId])) {
            throw new \DomainException;
        }
        
        $client = $this->requestIdClientMap[$requestId];
        
        if ($client->isWriting($requestId)) {
            throw new \RuntimeException(
                "Cannot set response (ID# $requestId); response output already started"
            );
        }
        
        $asgiEnv = $client->getRequest($requestId);
        $asgiResponse = $this->normalizeResponse($asgiEnv, $asgiResponse);
        
        $is100Continue = ($asgiResponse[0] != 100);
        
        if (!$is100Continue
            && $this->maxRequestsPerSession
            && $client->geRequestCount() >= $this->maxRequestsPerSession
        ) {
            $asgiResponse[2]['CONNECTION'] = 'close';
        }
        
        $client->setResponse($requestId, $asgiResponse);
        
        if ($this->insideBeforeResponseModLoop) {
            return;
        }
        
        $hostId = $asgiEnv['SERVER_NAME'] . ':' . $client->getServerPort();
        
        // Reload the response array in case it was altered by beforeResponse mods ...
        if ($this->doBeforeResponseMods($hostId, $requestId)) {
            $asgiResponse = $client->getResponse($requestId);
        }
        
        if ($shouldUpgrade = ($asgiResponse[0] == 101)) {
            $asgiResponse = $this->prepForProtocolUpgrade($asgiResponse);
            $client->setResponse($requestId, $asgiResponse);
        }
        
        $client->enqueueResponsesForWrite();
    }
    
    private function normalizeResponse(array $asgiEnv, array $asgiResponse) {
        list($status, $reason, $headers, $body) = $asgiResponse;
        
        if ($headers) {
            $headers = array_combine(array_map('strtoupper', array_keys($headers)), $headers);
        }
        
        if ($this->autoReasonPhrase && (string) $reason === '') {
            $reasonConst = 'Aerys\\Http\\Reasons::HTTP_' . $status;
            $reason = defined($reasonConst) ? constant($reasonConst) : $reason;
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
        
        if ($hasBody && empty($headers['CONTENT-TYPE'])) {
            $headers['CONTENT-TYPE'] = $this->defaultContentType;
        }
        
        $hasContentLength = isset($headers['CONTENT_LENGTH']);
        $isChunked = isset($headers['TRANSFER-ENCODING']) && !strcasecmp($headers['TRANSFER-ENCODING'], 'chunked');
        $isIterator = ($body instanceof \Iterator && !$body instanceof Http\MultiPartByteRangeBody);
        
        $protocol = $asgiEnv['SERVER_PROTOCOL'];
        $protocol = ($protocol == '1.0' || $protocol == '1.1') ? $protocol : '1.0';
        
        if ($hasBody && !$hasContentLength && is_string($body)) {
            $headers['CONTENT-LENGTH'] = strlen($body);
        } elseif ($hasBody && !$hasContentLength && is_resource($body)) {
            $currentPos = ftell($body);
            fseek($body, 0, SEEK_END);
            $headers['CONTENT-LENGTH'] = ftell($body) - $currentPos;
            fseek($body, $currentPos);
        } elseif ($hasBody && $protocol >= 1.1 && !$isChunked && $isIterator) {
            $headers['TRANSFER-ENCODING'] = 'chunked';
        } elseif ($hasBody && !$hasContentLength && $protocol < '1.1' && $isIterator) {
            $headers['CONNECTION'] = 'close';
        }
        
        return [$status, $reason, $headers, $body];
    }
    
    private function doBeforeResponseMods($hostId, $requestId) {
        if (!isset($this->beforeResponseMods[$hostId])) {
            return 0;
        }
        
        $modInvocationCount = 0;
        
        $this->insideBeforeResponseModLoop = TRUE;
        
        foreach ($this->beforeResponseMods[$hostId] as $mod) {
            try {
                $mod->beforeResponse($this, $requestId);
            } catch (\Exception $e) {
                $asgiEnv = $this->getRequest($requestId);
                $thrownBy = self::E_LOG_BEFORE_RESPONSE;
                $serverName = $asgiEnv['SERVER_NAME'];
                $requestUri = $asgiEnv['REQUEST_URI'];
                
                $this->logUserlandError($e, $thrownBy, $serverName, $requestUri);
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
            // @TODO Write message to error stream
            $status = 500;
            $reason = 'Internal Server Error';
            $body = '<html><body><h1>500 Internal Server Error</h1></body></html>';
            $headers = [
                'Content-Type' => 'text/html',
                'Content-Length' => strlen($body),
                'Connection' => 'close'
            ];
            
            return [$status, $reason, $headers, $body];
        }
    }
    
    function getResponse($requestId) {
        if (!isset($this->requestIdClientMap[$requestId])) {
            throw new \DomainException;
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
            throw new \DomainException;
        }
        
        $client = $this->requestIdClientMap[$requestId];
        
        if ($client->hasRequest($requestId)) {
            return $client->getRequest($requestId);
        } else {
            throw new \DomainException(
                "Request ID $requestId does not exist"
            );
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
    
    private function setErrorLogFile($filePath) {
        $this->errorLogFile = $filePath;
    }
    
    private function setHandleAfterHeaders($boolFlag) {
        $this->handleAfterHeaders = (bool) $boolFlag;
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
    }
    
}
























