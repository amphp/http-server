<?php

namespace Aerys\Handlers\ReverseProxy;

use Amp\Reactor,
    Aerys\Server,
    Aerys\Parsing\MessageParser,
    Aerys\Parsing\PeclMessageParser,
    Aerys\Writing\Writer,
    Aerys\Writing\StreamWriter,
    Aerys\Writing\ResourceException;

class ReverseProxyHandler {
    
    private $reactor;
    private $server;
    private $backends = [];
    private $connectionAttempts = [];
    private $backendConnectTimeout = 5;
    private $maxPendingRequests = 1500;
    private $proxyPassHeaders = [];
    private $ioGranularity = 262144;
    private $pendingRequests = 0;
    private $badGatewayResponse;
    private $serviceUnavailableResponse;
    
    function __construct(Reactor $reactor, Server $server, array $backends) {
        $this->reactor = $reactor;
        $this->server = $server;
        $this->canUsePeclParser = extension_loaded('http');
        $this->badGatewayResponse = $this->generateBadGatewayResponse();
        $this->serviceUnavailableResponse = $this->generateServiceUnavailableResponse();
        
        foreach ($backends as $backendUri) {
            $this->connect($backendUri);
        }
    }
    
    function setAllOptions(array $options) {
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }
    }
    
    function setOption($option, $value) {
        switch (strtolower($option)) {
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
    }
    
    private function setMaxPendingRequests($count) {
        $this->maxPendingRequests = filter_var($count, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'default' => 1500
        ]]);
    }
    
    private function setProxyPassHeaders(array $headers) {
        $this->proxyPassHeaders = array_change_key_case($headers, CASE_UPPER);
    }
    
    private function connect($uri) {
        $timeout = 42; // <--- not applicable with STREAM_CLIENT_ASYNC_CONNECT
        $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;
        $socket = @stream_socket_client($uri, $errNo, $errStr, $timeout, $flags);
        
        if ($socket || $errNo === SOCKET_EWOULDBLOCK) {
            $this->schedulePendingBackendTimeout($uri, $socket);
        } else {
            $errMsg = "Connection to proxy backend {$uri} failed: [{$errNo}] {$errStr}\n";
            fwrite($this->server->getErrorStream(), $errMsg);
            $this->doExponentialBackoff($uri);
        }
    }
    
    private function schedulePendingBackendTimeout($uri, $socket) {
        $subscription = $this->reactor->onWritable($socket, function($socket, $trigger) use ($uri) {
            $this->determinePendingConnectionResult($uri, $socket, $trigger);
        }, $this->backendConnectTimeout);
        
        $this->pendingBackendSubscriptions[$uri] = $subscription;
    }
    
    private function determinePendingConnectionResult($uri, $socket, $trigger) {
        $subscription = $this->pendingBackendSubscriptions[$uri];
        $subscription->cancel();
        
        unset($this->pendingBackendConnections[$uri]);
        
        if ($trigger === Reactor::TIMEOUT) {
            $errMsg = "Connection to proxy backend {$uri} failed: connect attempt timed out\n";
            fwrite($this->server->getErrorStream(), $errMsg);
            $this->doExponentialBackoff($uri);
        } elseif ($this->workaroundAsyncConnectBug($socket)) {
            $this->finalizeNewBackendConnection($uri, $socket);
        } else {
            $errMsg = "Connection to proxy backend {$uri} failed\n";
            fwrite($this->server->getErrorStream(), $errMsg);
            $this->doExponentialBackoff($uri);
        }
    }
    
    /**
     * This function exists to workaround asynchronously connected sockets that are erroneously
     * reported as writable when the connection actually failed. RFC 2616 requires servers to ignore
     * leading \r\n characters before the start of a message so we can get away with sending such
     * characters as a connectivity test.
     * 
     * @link https://bugs.php.net/bug.php?id=64803
     */
    private function workaroundAsyncConnectBug($socket) {
        return (@fwrite($socket, "\n") === 1);
    }
    
    private function doExponentialBackoff($uri) {
        if (isset($this->connectionAttempts[$uri])) {
            $maxWait = ($this->connectionAttempts[$uri] * 2) - 1;
            $this->connectionAttempts[$uri]++;
        } else {
            $this->connectionAttempts[$uri] = $maxWait = 1;
        }
        
        if ($secondsUntilRetry = rand(0, $maxWait)) {
            $reconnect = function() use ($uri) { $this->connect($uri); };
            $this->reactor->once($reconnect, $secondsUntilRetry);
        } else {
            $this->connect($uri);
        }
    }
    
    private function finalizeNewBackendConnection($uri, $socket) {
        unset($this->connectionAttempts[$uri]);
        
        stream_set_blocking($socket, FALSE);
        
        $backend = new Backend;
        $backend->uri = $uri;
        $backend->socket = $socket;
        
        $parser = $this->canUsePeclParser
            ? new PeclMessageParser(MessageParser::MODE_RESPONSE)
            : new MessageParser(MessageParser::MODE_RESPONSE);
        
        $parser->setOptions([
            'maxHeaderBytes' => 0,
            'maxBodyBytes' => 0
        ]);
        
        $backend->parser = $parser;
        
        $readSubscription = $this->reactor->onReadable($socket, function() use ($backend) {
            $this->readFromBackend($backend);
        });
        
        $backend->readSubscription = $readSubscription;
        
        $this->backends[$uri] = $backend;
    }
    
    private function readFromBackend(Backend $backend) {
        $data = @fread($backend->socket, $this->ioGranularity);
        
        if ($data || $data === '0') {
            $this->parseBackendData($backend, $data);
        } elseif (!is_resource($backend->socket) || @feof($backend->socket)) {
            $this->onDeadBackend($backend);
        }
    }
    
    private function parseBackendData(Backend $backend, $data) {
        while ($responseArr = $backend->parser->parse($data)) {
            $this->assignParsedResponse($backend, $responseArr);
            
            $parseBuffer = ltrim($backend->parser->getBuffer(), "\r\n");
            
            if ($parseBuffer || $parseBuffer === '0') {
                $data = '';
            } else {
                break;
            }
        }
    }
    
    private function assignParsedResponse(Backend $backend, array $responseArr) {
        $requestId = array_shift($backend->responseQueue);
        $headers = array_change_key_case($responseArr['headers'], CASE_UPPER);
        $asgiResponseHeaders = [];
        
        foreach ($headers as $key => $headerArr) {
            if (!($key === 'CONNECTION' || $key === 'TRANSFER-ENCODING')) {
                $asgiResponseHeaders[$key] = isset($headerArr[1])
                    ? implode(',', $headerArr)
                    : $headerArr[0];
            }
        }
        
        $asgiResponse = [
            $responseArr['status'],
            $responseArr['reason'],
            $asgiResponseHeaders,
            $responseArr['body']
        ];
        
        $this->pendingRequests--;
        
        $this->server->setResponse($requestId, $asgiResponse);
    }
    
    private function onDeadBackend(Backend $backend) {
        $unsentRequestIds = $backend->requestQueue ? array_keys($backend->requestQueue) : [];
        $proxiedRequestIds = $backend->responseQueue;
        $requestIds = array_merge($unsentRequestIds, $proxiedRequestIds);
        
        if ($requestIds) {
            foreach ($requestIds as $requestId) {
                $this->server->setResponse($requestId, $this->badGatewayResponse);
                $this->pendingRequests--;
            }
        }
        
        $backend->readSubscription->cancel();
        
        if ($backend->writeSubscription) {
            $backend->writeSubscription->cancel();
        }
        
        unset($this->backends[$backend->uri]);
        
        $this->connect($backend->uri);
    }
    
    /**
     * Proxy requests to backend servers
     * 
     * @param array $asgiEnv
     * @param int $requestId
     * @return void
     */
    function __invoke($asgiEnv, $requestId) {
        if (!$this->backends || $this->maxPendingRequests < $this->pendingRequests) {
            $this->server->setResponse($requestId, $this->serviceUnavailableResponse);
        } else {
            $backend = $this->selectBackend();
            $this->enqueueRequest($backend, $requestId, $asgiEnv);
            $this->writeRequestsToBackend($backend);
        }
    }
    
    private function selectBackend() {
        if (!$backend = current($this->backends)) {
            reset($this->backends);
            $backend = current($this->backends);
        }
        
        next($this->backends);
        
        return $backend;
    }
    
    function enqueueRequest(Backend $backend, $requestId, array $asgiEnv) {
        $headers = $this->generateRawHeadersFromEnvironment($asgiEnv);
        
        $writer = $asgiEnv['ASGI_INPUT']
            ? new StreamWriter($backend->socket, $headers, $asgiEnv['ASGI_INPUT'])
            : new Writer($backend->socket, $headers);
        
        $backend->requestQueue[$requestId] = $writer;
        
        $this->pendingRequests++;
    }
    
    private function generateRawHeadersFromEnvironment(array $asgiEnv) {
        $headerStr = $asgiEnv['REQUEST_METHOD'] . ' ' . $asgiEnv['REQUEST_URI'] . " HTTP/1.1\r\n";
        
        $headerArr = [];
        foreach ($asgiEnv as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $key = str_replace('_', '-', substr($key, 5));
                $headerArr[$key] = $value;
            }
        }
        
        $headerArr['CONNECTION'] = 'keep-alive';
        
        if ($this->proxyPassHeaders) {
            $headerArr = $this->mergeProxyPassHeaders($asgiEnv, $headerArr, $this->proxyPassHeaders);
        }
        
        foreach ($headerArr as $field => $value) {
            if ($value === (array) $value) {
                foreach ($value as $nestedValue) {
                    $headerStr .= "$field: $nestedValue\r\n";
                }
            } else {
                $headerStr .= "$field: $value\r\n";
            }
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
    
    private function writeRequestsToBackend(Backend $backend) {
        try {
            $didAllWritesComplete = TRUE;
            
            foreach ($backend->requestQueue as $requestId => $writer) {
                if ($writer->write()) {
                    unset($backend->requestQueue[$requestId]);
                    $backend->responseQueue[] = $requestId;
                } elseif ($backend->writeSubscription) {
                    $didAllWritesComplete = FALSE;
                    $backend->writeSubscription->enable();
                    break;
                } else {
                    $didAllWritesComplete = FALSE;
                    $writeSub = $this->reactor->onWritable($backend->socket, function() use ($backend) {
                        $this->writeRequestsToBackend($backend);
                    });
                    $backend->writeSubscription = $writeSub;
                    break;
                }
            }
            
            if ($didAllWritesComplete && $backend->writeSubscription) {
                $backend->writeSubscription->disable();
            }
            
        } catch (ResourceException $e) {
            $this->onDeadBackend($backend);
        }
    }
    
    function __destruct() {
        foreach ($this->backends as $backend) {
            $backend->readSubscription->cancel();
            if ($backend->writeSubscription) {
                $backend->writeSubscription->cancel();
            }
        }
    }
    
    private function generateBadGatewayResponse() {
        $status = 502;
        $reason = 'Bad Gateway';
        $body = "<html><body><h1>{$status} {$reason}</h1></body></html>";
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Length' => strlen($body)
        ];
        
        return [$status, $reason, $headers, $body];
    }
    
    private function generateServiceUnavailableResponse() {
        $status = 503;
        $reason = 'Service Unavailable';
        $body = "<html><body><h1>{$status} {$reason}</h1><hr /></body></html>";
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Length' => strlen($body)
        ];
        
        return [$status, $reason, $headers, $body];
    }
    
}
