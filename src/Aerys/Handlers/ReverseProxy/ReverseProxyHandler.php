<?php

namespace Aerys\Handlers\ReverseProxy;

use Amp\Reactor,
    Aerys\Server,
    Aerys\Parsing\Parser,
    Aerys\Parsing\MessageParser,
    Aerys\Parsing\PeclMessageParser,
    Aerys\Writing\Writer,
    Aerys\Writing\StreamWriter,
    Aerys\Writing\ResourceException;

class ReverseProxyHandler {
    
    private $reactor;
    private $server;
    private $backends;
    private $pendingBackends;
    private $connectAttemptCounts = [];
    private $backendConnectTimeout = 5;
    private $maxPendingRequests = 1500;
    private $proxyPassHeaders = [];
    private $ioGranularity = 262144;
    private $pendingRequests = 0;
    private $debug = FALSE;
    private $debugColors = FALSE;
    private $ansiColors = [
        'red' => '1;31',
        'green' => '1;32',
        'yellow' => '1;33'
    ];
    private $badGatewayResponse;
    private $serviceUnavailableResponse;
    
    function __construct(Reactor $reactor, Server $server, array $backends) {
        $this->reactor = $reactor;
        $this->server = $server;
        $this->backends = new \SplObjectStorage;
        $this->pendingBackends = new \SplObjectStorage;
        $this->canUsePeclParser = extension_loaded('http');
        $this->badGatewayResponse = $this->generateBadGatewayResponse();
        $this->serviceUnavailableResponse = $this->generateServiceUnavailableResponse();
        
        foreach ($backends as $backendUri) {
            for ($i=0;$i<4;$i++) {
                $this->connect($backendUri);
            }
        }
    }
    
    function setAllOptions(array $options) {
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }
    }
    
    function setOption($option, $value) {
        switch (strtolower($option)) {
            case 'debug':
                $this->setDebug($value);
                break;
            case 'debugcolors':
                $this->setDebugColors($value);
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
    }
    
    private function setDebug($boolFlag) {
        $this->debug = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }
    
    private function setDebugColors($boolFlag) {
        $this->debugColors = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
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
            $debugMsg = NULL;
            $backend = new Backend;
            $backend->uri = $uri;
            $backend->socket = $socket;
            $this->schedulePendingBackendTimeout($backend);
        } else {
            $debugMsg = "PRX: Backend proxy connect failed ({$uri}): [{$errNo}] {$errStr}";
            $this->doExponentialBackoff($uri);
        }
        
        if ($debugMsg && $this->debug) {
            $this->debug($debugMsg, 'yellow');
        }
    }
    
    private function debug($msg, $color = NULL) {
        echo ($this->debugColors && $color)
            ? "\033[{$this->ansiColors[$color]}m{$msg}\n\033[0m"
            : "{$msg}\n";
    }
    
    private function schedulePendingBackendTimeout(Backend $backend) {
        $sock = $backend->socket;
        $subscription = $this->reactor->onWritable($sock, function($sock, $trigger) use ($backend) {
            $this->determinePendingConnectionResult($backend, $trigger);
        }, $this->backendConnectTimeout);
        
        $this->pendingBackends->attach($backend, $subscription);
    }
    
    private function determinePendingConnectionResult(Backend $backend, $trigger) {
        $subscription = $this->pendingBackends->offsetGet($backend);
        $subscription->cancel();
        
        $this->pendingBackends->detach($backend);
        
        $uri = $backend->uri;
        $socket = $backend->socket;
        
        if ($trigger === Reactor::TIMEOUT) {
            $debugMsg = "PRX: Backend proxy connect failed ({$uri}): connect attempt timed out";
            $this->doExponentialBackoff($uri);
        } elseif ($this->workaroundAsyncConnectBug($socket)) {
            $debugMsg = NULL;
            $this->finalizeNewBackendConnection($backend);
        } else {
            $debugMsg = "PRX: Backend proxy connect failed ({$uri}): could not connect";
            $this->doExponentialBackoff($uri);
        }
        
        if ($debugMsg && $this->debug) {
            $this->debug($debugMsg, 'yellow');
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
        return (@fwrite($socket, "\n") === 1) && !@feof($socket);
    }
    
    private function doExponentialBackoff($uri) {
        if (isset($this->connectAttemptCounts[$uri])) {
            $maxWait = ($this->connectAttemptCounts[$uri] * 2) - 1;
            $this->connectAttemptCounts[$uri]++;
        } else {
            $this->connectAttemptCounts[$uri] = $maxWait = 1;
        }
        
        if ($secondsUntilRetry = rand(0, $maxWait)) {
            $reconnect = function() use ($uri) { $this->connect($uri); };
            $this->reactor->once($reconnect, $secondsUntilRetry);
        } else {
            $this->connect($uri);
        }
    }
    
    private function finalizeNewBackendConnection(Backend $backend) {
        unset($this->connectAttemptCounts[$backend->uri]);
        
        if ($this->debug) {
            $debugMsg = "PRX: Connected to backend server: {$backend->uri}";
            $this->debug($debugMsg, 'green');
        }
        
        stream_set_blocking($backend->socket, FALSE);
        
        $parser = $this->canUsePeclParser
            ? new PeclMessageParser(MessageParser::MODE_RESPONSE)
            : new MessageParser(MessageParser::MODE_RESPONSE);
        
        $parser->setOptions([
            'maxHeaderBytes' => 0,
            'maxBodyBytes' => 0
        ]);
        
        $backend->parser = $parser;
        
        $readSubscription = $this->reactor->onReadable($backend->socket, function() use ($backend) {
            $this->readFromBackend($backend);
        });
        
        $backend->readSubscription = $readSubscription;
        
        $this->backends->attach($backend);
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
        $responseHeaders = [];
        foreach ($responseArr['headers'] as $key => $headerArr) {
            if (strcasecmp($key,'Keep-Alive')) {
                foreach ($headerArr as $value) {
                    $responseHeaders[] = "{$key}: $value";
                }
            }
        }
        
        $asgiResponse = [
            $responseArr['status'],
            $responseArr['reason'],
            $responseHeaders,
            $responseArr['body']
        ];
        
        $this->pendingRequests--;
        
        if ($this->debug) {
            $requestUri = $this->server->getRequest($requestId)['REQUEST_URI'];
            $msg = "PRX: Backend response ({$backend->uri}): {$requestUri}";
            $this->debug($msg, 'green');
            $msg = "-------------------------------------------------------\n";
            $msg.= $responseArr['trace'];
            $msg.= "-------------------------------------------------------";
            $this->debug($msg);
        }
        
        $this->server->setResponse($requestId, $asgiResponse);
    }
    
    private function onDeadBackend(Backend $backend) {
        if ($this->debug) {
            $debugMsg = "PRX: Backend server closed connection: {$backend->uri}";
            $this->debug($debugMsg, 'red');
        }
        
        $this->backends->detach($backend);
        
        $backend->readSubscription->cancel();
        
        if ($backend->writeSubscription) {
            $backend->writeSubscription->cancel();
        }
        
        if ($backend->parser->getState() === Parser::BODY_IDENTITY_EOF) {
            $responseArr = $backend->parser->getParsedMessageArray();
            $this->assignParsedResponse($backend, $responseArr);
        }
        
        $requestIdsToFail = $backend->responseQueue;
        $hasUnsentRequests = (bool) $backend->requestQueue;
        
        if ($hasUnsentRequests && $this->backends->count()) {
            $this->reallocateRequestsFromDeadBackend($backend);
        } elseif ($hasUnsentRequests) {
            $requestIdsToFail = array_merge($backend->requestQueue, $proxiedRequestIds);
        }
        
        foreach ($requestIdsToFail as $requestId) {
            $this->doBadGatewayResponse($requestId);
        }
        
        $this->connect($backend->uri);
    }
    
    private function reallocateRequests(Backend $deadBackend) {
        if ($this->backends->count()) {
            foreach ($deadBackend->requestQueue as $requestId => $asgiEnv) {
                $backend = $this->selectBackend();
                $this->enqueueRequest($backend, $requestId, $asgiEnv);
                $this->writeRequestsToBackend($backend);
            }
        }
    }
    
    private function doBadGatewayResponse($requestId) {
        if ($this->debug) {
            $debugMsg = "PRX: Sending 502 for request ID {$requestId} (lost backend connection)";
            $this->debug($debugMsg);
        }
        $this->server->setResponse($requestId, $this->badGatewayResponse);
        $this->pendingRequests--;
    }
    
    /**
     * Proxy requests to backend servers
     * 
     * @param array $asgiEnv
     * @param int $requestId
     * @return void
     */
    function __invoke($asgiEnv, $requestId) {
        if (!$this->backends->count() || $this->maxPendingRequests < $this->pendingRequests) {
            $this->server->setResponse($requestId, $this->serviceUnavailableResponse);
        } else {
            $backend = $this->selectBackend();
            $this->enqueueRequest($backend, $requestId, $asgiEnv);
            $this->writeRequestsToBackend($backend);
        }
    }
    
    private function selectBackend() {
        if (!$backend = $this->backends->current()) {
            $this->backends->rewind();
            $backend = $this->backends->current();
        }
        
        $this->backends->next();
        
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
            'Content-Type: text/html; charset=utf-8',
            'Content-Length: ' . strlen($body)
        ];
        
        return [$status, $reason, $headers, $body];
    }
    
    private function generateServiceUnavailableResponse() {
        $status = 503;
        $reason = 'Service Unavailable';
        $body = "<html><body><h1>{$status} {$reason}</h1><hr /></body></html>";
        $headers = [
            'Content-Type: text/html; charset=utf-8',
            'Content-Length: ' . strlen($body)
        ];
        
        return [$status, $reason, $headers, $body];
    }
    
}
