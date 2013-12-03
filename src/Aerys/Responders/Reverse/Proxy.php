<?php

namespace Aerys\Responders\Reverse;

use Alert\Reactor,
    Aerys\Server,
    Aerys\Parsing\Parser,
    Aerys\Parsing\MessageParser,
    Aerys\Parsing\PeclMessageParser,
    Aerys\Writing\Writer,
    Aerys\Writing\StreamWriter,
    Aerys\Writing\ResourceException,
    Aerys\Responders\AsgiResponder;

class Proxy implements AsgiResponder {

    private $reactor;
    private $server;
    private $backends = [];
    private $pendingConnections = [];
    private $queuedRequests = [];
    private $totalPendingRequests = 0;
    private $ioGranularity = 262144;
    private $maxPendingRequests = 1024;
    private $proxyPassHeaders = [];
    private $loWaterConnectionMin = 4;
    private $hiWaterConnectionMax = 10;
    private $badGatewayResponse;
    private $serviceUnavailableResponse;

    function __construct(Reactor $reactor, Server $server) {
        $this->reactor = $reactor;
        $this->server = $server;
        $this->canUsePeclParser = extension_loaded('http');
        $this->badGatewayResponse = [
            $status = 502,
            $reason = 'Bad Gateway',
            $headers = ['Content-Type: text/html; charset=utf-8'],
            $body = "<html><body><h1>502 Bad Gateway</h1></body></html>"
        ];
        $this->serviceUnavailableResponse = [
            $status = 503,
            $reason = 'Service Unavailable',
            $headers = ['Content-Type: text/html; charset=utf-8'],
            $body = "<html><body><h1>503 Service Unavailable</h1></body></html>"
        ];
    }

    /**
     * Respond to the specified ASGI request environment
     *
     * @param array $asgiEnv The ASGI request
     * @param int $requestId The unique Aerys request identifier
     * @return mixed Returns ASGI response array or NULL for delayed async response
     */
    function __invoke(array $asgiEnv, $requestId) {
        return ($this->backends && $this->maxPendingRequests > $this->totalPendingRequests)
            ? $this->dispatchRequestToBackend($asgiEnv, $requestId)
            : $this->serviceUnavailableResponse;
    }

    /**
     * Add a backend server
     *
     * Once added, the proxy will attempt to connect to new backend servers in the next iteration
     * of the event loop.
     * 
     * @param string $uri A backend server URI e.g. (127.0.0.1:1337 or localhost:80)
     * @return void
     */
    function addBackend($uri) {
        $uri = $this->validateBackendUri($uri);
        if (!isset($this->backends[$uri])) {
            $backend = new Backend;
            $backend->uri = $uri;
            $this->backends[$uri] = $backend;
            $this->reactor->immediately(function() use ($backend) {
                $this->doInitialBackendConnect($backend);
            });
        }
    }
    
    private function doInitialBackendConnect(Backend $backend) {
        for ($i = 0; $i < $this->loWaterConnectionMin; $i++) {
            $this->connect($backend);
        }
    }

    private function validateBackendUri($uri) {
        if (!is_string($uri)) {
            throw new \InvalidArgumentException(
                "Invalid proxy backend URI: string required"
            );
        }
        $urlParts = @parse_url($uri);
        if (empty($urlParts['host']) || empty($urlParts['port'])) {
            throw new \InvalidArgumentException(
                "Invalid proxy backend URI: {$uri}"
            );
        }
        
        return $urlParts['host'] . ':' . $urlParts['port'];
    }

    /**
     * Set multiple proxy options at once
     *
     * @param array $options Key-value array mapping option name keys to values
     * @return \Aerys\Responders\Reverse\Proxy Returns the current object instance
     */
    function setAllOptions(array $options) {
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }

        return $this;
    }

    /**
     * Set a proxy option
     *
     * @param string $option The option key (case-insensitve)
     * @param mixed $value The option value to assign
     * @throws \DomainException On unrecognized option key
     * @return \Aerys\Responders\Reverse\Proxy Returns the current object instance
     */
    function setOption($option, $value) {
        switch (strtolower($option)) {
            case 'lowaterconnectionmin':
                $this->setLoWaterConnectionMin($value);
                break;
            case 'hiwaterconnectionmax':
                $this->setHiWaterConnectionMax($value);
                break;
            case 'maxpendingrequests':
                $this->setMaxPendingRequests($value);
                break;
            case 'proxypassheaders':
                $this->setProxyPassHeaders($value);
                break;
            default:
                throw new \DomainException(
                    "Unrecognized option: {$option}"
                );
        }

        return $this;
    }

    private function setLoWaterConnectionMin($count) {
        $this->loWaterConnectionMin = filter_var($count, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'max_range' => 100,
            'default' => 4
        ]]);
    }

    private function setHiWaterConnectionMax($count) {
        $this->hiWaterConnectionMax = filter_var($count, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'default' => 100
        ]]);
    }

    private function setMaxPendingRequests($count) {
        $this->maxPendingRequests = filter_var($count, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'default' => 1024
        ]]);
    }

    private function setProxyPassHeaders(array $headers) {
        $this->proxyPassHeaders = array_change_key_case($headers, CASE_UPPER);
    }

    /**
     * @TODO Maybe add connect/response timeouts?
     */
    private function connect(Backend $backend) {
        $backend->cachedConnectionCount++;

        $timeout = 42; // <--- not applicable with STREAM_CLIENT_ASYNC_CONNECT
        $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;
        $socket = @stream_socket_client($backend->uri, $errNo, $errStr, $timeout, $flags);

        if ($socket) {
            $watcherId = $this->reactor->onWritable($socket, function($watcherId, $socket) {
                $this->onConnectResolution($watcherId, $socket);
            });
            $this->pendingConnections[$watcherId] = $backend;
        } else {
            $backend->cachedConnectionCount--;
            $this->onConnectionFailure($backend);
        }
    }

    private function onConnectResolution($watcherId, $socket) {
        $this->reactor->cancel($watcherId);
        $backend = $this->pendingConnections[$watcherId];
        unset($this->pendingConnections[$watcherId]);

        return (@feof($socket))
            ? $this->onConnectionFailure($backend)
            : $this->finalizeNewBackendConnection($backend, $socket);
    }

    private function onConnectionFailure(Backend $backend) {
        $backend->cachedConnectionCount--;
        $backend->consecutiveConnectFailures++;
        $maxWait = ($backend->consecutiveConnectFailures * 2) - 1;

        if ($secondsUntilRetry = rand(0, $maxWait)) {
            $reconnect = function() use ($backend) { $this->connect($backend); };
            $this->reactor->once($reconnect, $secondsUntilRetry);
        } else {
            $this->connect($backend);
        }
    }

    private function finalizeNewBackendConnection(Backend $backend, $socket) {
        stream_set_blocking($socket, FALSE);

        $conn = new Connection;
        $conn->id = (int) $socket;
        $conn->uri = $backend->uri;
        $conn->socket = $socket;

        $responseParser = $this->canUsePeclParser
            ? new PeclMessageParser(MessageParser::MODE_RESPONSE)
            : new MessageParser(MessageParser::MODE_RESPONSE);

        $responseParser->setOptions([
            'maxHeaderBytes' => 0,
            'maxBodyBytes' => 0
        ]);

        $conn->responseParser = $responseParser;
        $conn->readWatcher = $this->reactor->onReadable($socket, function() use ($conn) {
            $this->readFromBackendConnection($conn);
        });
        $conn->writeWatcher = $this->reactor->onWritable($socket, function() use ($conn) {
            $this->writeToBackendConnection($conn);
        }, $enableNow = FALSE);

        $backend->connections[$conn->id] = $conn;
        $backend->consecutiveConnectFailures = 0;

        if ($this->queuedRequests) {
            $requestId = key($this->queuedRequests);
            $asgiEnv = $this->queuedRequests[$requestId];
            unset($this->queuedRequests[$requestId]);
            $this->enqueueRequest($conn, $requestId, $asgiEnv);
        } else {
            $backend->availableConnections[$conn->id] = $conn;
        }
    }

    private function readFromBackendConnection(Connection $conn) {
        $data = @fread($conn->socket, $this->ioGranularity);

        if ($data || $data === '0') {
            $this->parseBackendData($conn, $data);
        } elseif (!is_resource($conn->socket) || @feof($conn->socket)) {
            $this->onDeadConnection($conn);
        }
    }

    private function parseBackendData(Connection $conn, $data) {
        try {
            while ($responseArr = $conn->responseParser->parse($data)) {
                $this->receiveBackendResponse($conn, $responseArr);
                $parseBuffer = ltrim($conn->responseParser->getBuffer(), "\r\n");
                if ($parseBuffer || $parseBuffer === '0') {
                    $data = '';
                } else {
                    break;
                }
            }
        } catch (ParseException $e) {
            if ($requestId = $conn->inProgressRequestId) {
                $conn->inProgressRequestId = NULL;
                $this->server->setResponse($requestId, $this->badGatewayResponse);
            }

            $backend = $this->backends[$conn->uri];
            $this->unloadBackendConnection($backend, $conn);
        }
    }

    private function receiveBackendResponse(Connection $conn, array $responseArr, $isClosed = FALSE) {
        $protocol = $responseArr['protocol'];
        $headers = $responseArr['headers'];
        $responseHeaders = [];
        foreach ($headers as $field => $valueArr) {
            $ucField = strtoupper($field);
            if (!($ucField === 'KEEP-ALIVE'
                || $ucField === 'CONNECTION'
                || $ucField === 'TRANSFER-ENCODING'
                || $ucField === 'CONTENT-LENGTH'
            )) {
                foreach ($valueArr as $value) {
                    $responseHeaders[] = "{$field}: $value";
                }
            }
        }

        $asgiResponse = [
            (int) $responseArr['status'],
            $responseArr['reason'],
            $responseHeaders,
            $responseArr['body']
        ];

        $this->totalPendingRequests--;
        $requestId = $conn->inProgressRequestId;
        $conn->inProgressRequestId = NULL;
        $this->server->setResponse($requestId, $asgiResponse);
        $backend = $this->backends[$conn->uri];

        if ($isClosed || $this->shouldCloseAfterResponse($protocol, $headers)) {
            $this->unloadBackendConnection($backend, $conn);
        } elseif ($this->queuedRequests) {
            $requestId = key($this->queuedRequests);
            $asgiEnv = $this->queuedRequests[$requestId];
            unset($this->queuedRequests[$requestId]);
            $this->enqueueRequest($conn, $requestId, $asgiEnv);
        } else {
            $backend->availableConnections[$conn->id] = $conn;
        }
    }

    private function shouldCloseAfterResponse($protocol, array $headers) {
        $protocol = (string) $protocol;
        $headers = array_change_key_case($headers, CASE_UPPER);

        if ($hasConnectionHeader = isset($headers['CONNECTION'])) {
            $mergedConnHeader = implode($headers['CONNECTION']);
            $hasExplicitClose = !stristr($mergedConnHeader, 'close');
            $hasExplicitKeepAlive = !stristr($mergedConnHeader, 'keep-alive');
        }

        if ($protocol === '1.1' && $hasConnectionHeader) {
            $shouldClose = $hasExplicitClose;
        } elseif ($protocol === '1.1') {
            $shouldClose = FALSE;
        } elseif ($protocol === '1.0' && $hasConnectionHeader && $hasExplicitKeepAlive) {
            $shouldClose = FALSE;
        } else {
            $shouldClose = TRUE;
        }

        return $shouldClose;
    }

    private function unloadBackendConnection(Backend $backend, Connection $conn) {
        $this->reactor->cancel($conn->readWatcher);
        $this->reactor->cancel($conn->writeWatcher);
        $conn->requestParser = NULL;
        $conn->inProgressRequestId = NULL;

        if (is_resource($conn->socket)) {
            @fclose($conn->socket);
        }

        unset(
            $backend->connections[$conn->id],
            $backend->availableConnections[$conn->id]
        );
        $backend->cachedConnectionCount--;

        if ($backend->cachedConnectionCount < $this->loWaterConnectionMin) {
            $this->connect($backend);
        }
    }

    private function onDeadConnection(Connection $conn) {
        if ($conn->responseParser->getState() === Parser::BODY_IDENTITY_EOF) {
            $responseArr = $conn->responseParser->getParsedMessageArray();
            $this->receiveBackendResponse($conn, $responseArr, $isClosed = TRUE);
        } elseif ($requestId = $conn->inProgressRequestId) {
            $this->totalPendingRequests--;
            $this->server->setResponse($requestId, $this->badGatewayResponse);
            $backend = $this->backends[$conn->uri];
            $this->unloadBackendConnection($backend, $conn);
        } else {
            $backend = $this->backends[$conn->uri];
            $this->unloadBackendConnection($backend, $conn);
        }
    }

    /**
     * @TODO Utilize intelligent (not round-robin) backend selection
     */
    private function dispatchRequestToBackend(array $asgiEnv, $requestId) {
        if (!$backend = current($this->backends)) {
            reset($this->backends);
            $backend = current($this->backends);
        }

        next($this->backends);

        if ($backend->availableConnections) {
            $this->totalPendingRequests++;
            $connId = key($backend->availableConnections);
            $conn = $backend->availableConnections[$connId];
            unset($backend->availableConnections[$connId]);
            $this->enqueueRequest($conn, $requestId, $asgiEnv);
            $asgiResponse = NULL;
        } elseif ($backend->cachedConnectionCount < $this->hiWaterConnectionMax) {
            $this->totalPendingRequests++;
            $this->queuedRequests[$requestId] = $asgiEnv;
            $this->connect($backend);
            $asgiResponse = NULL;
        } else {
            $asgiResponse = $this->serviceUnavailableResponse;
        }

        return $asgiResponse;
    }

    private function enqueueRequest(Connection $conn, $requestId, array $asgiEnv) {
        $rawHeaders = $this->generateRawHeadersFromEnvironment($asgiEnv);

        $conn->responseParser->enqueueResponseMethodMatch($asgiEnv['REQUEST_METHOD']);
        $conn->inProgressRequestId = $requestId;
        $conn->inProgressRequestWriter = $asgiEnv['ASGI_INPUT']
            ? new StreamWriter($conn->socket, $rawHeaders, $asgiEnv['ASGI_INPUT'])
            : new Writer($conn->socket, $rawHeaders);

        $this->writeToBackendConnection($conn);
    }

    private function generateRawHeadersFromEnvironment(array $asgiEnv) {
        unset(
            $asgiEnv['HTTP_EXPECT'],
            $asgiEnv['HTTP_CONTENT_LENGTH'],
            $asgiEnv['HTTP_TRANSFER_ENCODING']
        );
        
        $headerStr = $asgiEnv['REQUEST_METHOD'] . ' ' . $asgiEnv['REQUEST_URI'] . " HTTP/1.1\r\n";

        $headerArr = [];
        foreach ($asgiEnv as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $key = str_replace('_', '-', substr($key, 5));
                $headerArr[$key] = $value;
            }
        }
        
        $headerArr['CONNECTION'] = 'keep-alive';
        
        if ($body = $asgiEnv['ASGI_INPUT']) {
            fseek($body, 0, SEEK_END);
            $headerArr['CONTENT_LENGTH'] = ftell($body);
            rewind($body);
        }

        if ($this->proxyPassHeaders) {
            $headerArr = $this->mergeProxyPassHeaders($asgiEnv, $headerArr, $this->proxyPassHeaders);
        }

        foreach ($headerArr as $field => $value) {
            $headerStr .= "$field: $value\r\n";
        }

        $headerStr .= "\r\n";

        return $headerStr;
    }

    private function mergeProxyPassHeaders(array $asgiEnv, array $headerArr, array $proxyPassHeaders) {
        $host = $asgiEnv['SERVER_NAME'];
        $port = $asgiEnv['SERVER_PORT'];

        if (!($port == 80 || $port == 443)) {
            $host .= ":{$port}";
        }

        $availableVars = [
            '$host' => $host,
            '$serverName' => $asgiEnv['SERVER_NAME'],
            '$serverAddr' => $asgiEnv['SERVER_ADDR'],
            '$serverPort' => $asgiEnv['SERVER_PORT'],
            '$remoteAddr' => $asgiEnv['REMOTE_ADDR']
        ];

        foreach ($proxyPassHeaders as $key => $value) {
            if (isset($availableVars[$value])) {
                $proxyPassHeaders[$key] = $availableVars[$value];
            }
        }

        return array_merge($headerArr, $proxyPassHeaders);
    }

    private function writeToBackendConnection(Connection $conn) {
        try {
            if ($conn->inProgressRequestWriter->write()) {
                $this->reactor->disable($conn->writeWatcher);
                $conn->inProgressRequestWriter = NULL;
            } else {
                $this->reactor->enable($conn->writeWatcher);
            }
        } catch (ResourceException $e) {
            $conn->inProgressRequestWriter = NULL;
            $this->onDeadConnection($conn);
        }
    }

    function __destruct() {
        foreach ($this->backends as $backend) {
            foreach ($backend->connections as $conn) {
                $this->reactor->cancel($conn->readWatcher);
                $this->reactor->cancel($conn->writeWatcher);
            }
        }
    }

}
