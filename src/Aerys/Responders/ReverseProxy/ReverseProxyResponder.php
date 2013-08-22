<?php

namespace Aerys\Responders\ReverseProxy;

use Alert\Reactor,
    Aerys\Server,
    Aerys\Parsing\Parser,
    Aerys\Parsing\MessageParser,
    Aerys\Parsing\PeclMessageParser,
    Aerys\Writing\Writer,
    Aerys\Writing\StreamWriter,
    Aerys\Writing\ResourceException,
    Aerys\Responders\AsgiResponder;

class ReverseProxyResponder implements AsgiResponder {

    private $reactor;
    private $server;
    private $backends;
    private $pendingBackends;
    private $connectionAttempts = [];
    private $maxPendingRequests = 1500;
    private $proxyPassHeaders = [];
    private $ioGranularity = 262144;
    private $pendingRequests = 0;
    private $poolSize = 4;
    private $debug = FALSE;
    private $debugColors = FALSE;
    private $ansiColors = [
        'red' => '1;31',
        'green' => '1;32',
        'yellow' => '1;33'
    ];
    private $badGatewayResponse;
    private $serviceUnavailableResponse;

    function __construct(Reactor $reactor, Server $server) {
        $this->reactor = $reactor;
        $this->server = $server;
        $this->backends = new \SplObjectStorage;
        $this->pendingBackends = new \SplObjectStorage;
        $this->canUsePeclParser = extension_loaded('http');
        $this->badGatewayResponse = $this->generateBadGatewayResponse();
        $this->serviceUnavailableResponse = $this->generateServiceUnavailableResponse();
    }

    /**
     * @TODO
     */
    function addBackend($uri) {
        for ($i = 0; $i < $this->poolSize; $i++) {
            $this->connect($uri);
        }
    }

    /**
     * Set multiple ReverseProxy options
     *
     * @param array $options Key-value array mapping option name keys to values
     * @return \Aerys\Responders\ReverseProxy\ReverseProxyResponder Returns the current object instance
     */
    function setAllOptions(array $options) {
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }

        return $this;
    }

    /**
     * Set a ReverseProxy option
     *
     * @param string $option The option key (case-insensitve)
     * @param mixed $value The option value to assign
     * @throws \DomainException On unrecognized option key
     * @return \Aerys\Responders\ReverseProxy\ReverseProxyResponder Returns the current object instance
     */
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

        return $this;
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
            $this->pendingBackends->attach($backend);

            $backend->uri = $uri;
            $backend->socket = $socket;
            $connectWatcher = $this->reactor->onWritable($socket, function($socket) use ($backend) {
                $this->determinePendingConnectionResult($backend);
            });
            $backend->connectWatcher = $connectWatcher;

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

    private function determinePendingConnectionResult(Backend $backend) {
        $this->reactor->cancel($backend->connectWatcher);
        $this->pendingBackends->detach($backend);

        $socket = $backend->socket;

        if (!@feof($socket)) {
            $debugMsg = NULL;
            $this->finalizeNewBackendConnection($backend);
        } else {
            $uri = $backend->uri;
            $debugMsg = "PRX: Backend proxy connect failed ({$uri}): could not connect";
            $this->doExponentialBackoff($uri);
        }

        if ($debugMsg && $this->debug) {
            $this->debug($debugMsg, 'yellow');
        }
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

    private function finalizeNewBackendConnection(Backend $backend) {
        unset($this->connectionAttempts[$backend->uri]);

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

        $readWatcher = $this->reactor->onReadable($backend->socket, function() use ($backend) {
            $this->readFromBackend($backend);
        });

        $writeWatcher = $this->reactor->onWritable($backend->socket, function() use ($backend) {
            $this->writeToBackend($backend);
        }, $enableNow = FALSE);

        $backend->readWatcher = $readWatcher;
        $backend->writeWatcher = $writeWatcher;

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
            $ucKey = strtoupper($key);
            if (!($ucKey === 'KEEP-ALIVE'
                || $ucKey === 'CONNECTION'
                || $ucKey === 'TRANSFER-ENCODING'
                || $ucKey === 'CONTENT-LENGTH'
            )) {
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

        $this->reactor->cancel($backend->readWatcher);
        $this->reactor->cancel($backend->writeWatcher);

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
                $this->writeToBackend($backend);
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
     * Respond to the specified ASGI request environment
     *
     * @param array $asgiEnv The ASGI request
     * @param int $requestId The unique Aerys request identifier
     * @return mixed Returns ASGI response array or NULL for delayed async response
     */
    function __invoke(array $asgiEnv, $requestId) {
        if (!$this->backends->count() || $this->maxPendingRequests < $this->pendingRequests) {
            return $this->serviceUnavailableResponse;
        } else {
            $backend = $this->selectBackend();
            $this->enqueueRequest($backend, $requestId, $asgiEnv);
            $this->writeToBackend($backend);
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

    private function enqueueRequest(Backend $backend, $requestId, array $asgiEnv) {
        $headers = $this->generateRawHeadersFromEnvironment($asgiEnv);
        $backend->parser->enqueueResponseMethodMatch($asgiEnv['REQUEST_METHOD']);
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

    private function writeToBackend(Backend $backend) {
        try {
            $didAllWritesComplete = TRUE;

            foreach ($backend->requestQueue as $requestId => $writer) {
                if ($writer->write()) {
                    unset($backend->requestQueue[$requestId]);
                    $backend->responseQueue[] = $requestId;
                } else {
                    $didAllWritesComplete = FALSE;
                    $this->reactor->enable($backend->writeWatcher);
                    break;
                }
            }

            if ($didAllWritesComplete) {
                $this->reactor->disable($backend->writeWatcher);
            }

        } catch (ResourceException $e) {
            $this->onDeadBackend($backend);
        }
    }

    function __destruct() {
        foreach ($this->backends as $backend) {
            $this->reactor->cancel($backend->readWatcher);
            $this->reactor->cancel($backend->writeWatcher);
            $this->reactor->cancel($backend->connectWatcher);
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
