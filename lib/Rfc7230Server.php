<?php

namespace Aerys;

use Amp\{
    Struct,
    Reactor,
    Promise,
    Success,
    Deferred,
    function resolve
};

class Rfc7230Server implements ServerObserver {
    use Struct;
    private $reactor;
    private $vhostGroup;
    private $options;
    private $bodyNull;
    private $exports;
    private $stopPromisor;
    private $currentTime;
    private $currentHttpDate;
    private $timeUpdateWatcher;
    private $clients = [];
    private $clientCount = 0;
    private $pendingTlsStreams = [];
    private $keepAliveTimeouts = [];
    private $keepAliveWatcher;
    private $serverInfo;
    private $exporter;

    /* private callables that we pass to external code */
    private $negotiateCrypto;
    private $updateTime;
    private $clearExport;
    private $onCoroutineAppResolve;
    private $onCompletedResponse;
    private $startResponseFilter;
    private $genericResponseFilter;
    private $headResponseFilter;
    private $deflateResponseFilter;
    private $chunkResponseFilter;
    private $bufferResponseFilter;

    public function __construct(Reactor $reactor, VhostGroup $vhostGroup, Options $options) {
        $this->reactor = $reactor;
        $this->vhostGroup = $vhostGroup;
        $this->options = $options;
        $this->bodyNull = new BodyNull;
        $this->exporter = function(Rfc7230Client $client) {
            ($client->onUpgrade)($client->socket, $this->export($client));
        };

        // We have some internal callables that have to be passed to outside
        // code. We don't want these to be part of the public API and it's
        // preferable to avoid the extra fcall from wrapping them in a closure
        // with automatic $this scope binding. Instead we use reflection to turn
        // them into closures that we can pass as "public" callbacks.
        $this->negotiateCrypto = $this->makePrivateMethodClosure("negotiateCrypto");
        $this->updateTime = $this->makePrivateMethodClosure("updateTime");
        $this->clearExport = $this->makePrivateMethodClosure("clearExport");
        $this->onCoroutineAppResolve = $this->makePrivateMethodClosure("onCoroutineAppResolve");
        $this->onCompletedResponse = $this->makePrivateMethodClosure("onCompletedResponse");
        $this->startResponseFilter = $this->makePrivateMethodClosure("startResponseFilter");
        $this->genericResponseFilter = $this->makePrivateMethodClosure("genericResponseFilter");
        $this->headResponseFilter = $this->makePrivateMethodClosure("headResponseFilter");
        $this->deflateResponseFilter = $this->makePrivateMethodClosure("deflateResponseFilter");
        $this->chunkResponseFilter = $this->makePrivateMethodClosure("chunkResponseFilter");
        $this->bufferResponseFilter = $this->makePrivateMethodClosure("bufferResponseFilter");
    }

    public function __debugInfo() {
        // Check for boolean on $this->serverInfo because it isn't available
        // until we observe a Server::STARTING notification
        $serverInfo = $this->serverInfo ?: ["state" => "STOPPED", "boundAddresses" => []];
        $localInfo = [
            "clients"           => $this->clientCount,
            "currentTime"       => $this->currentTime,
            "currentHttpDate"   => $this->currentHttpDate,
        ];

        return array_merge($serverInfo, $localInfo);
    }

    private function makePrivateMethodClosure(string $method): \Closure {
        return (new \ReflectionClass($this))->getMethod($method)->getClosure($this);
    }

    /**
     * Import a client socket stream for HTTP protocol manipulation
     *
     * @param resource $socket
     * @return void
     */
    public function import($socket) {
        if ($this->clientCount++ === $this->options->maxConnections) {
            $this->clientCount--;
            @fclose($socket);
            return;
        }

        stream_set_blocking($socket, false);
        if (@stream_context_get_options($socket)["ssl"]) {
            $socketId = (int) $socket;
            $watcherId = $this->reactor->onReadable($socket, $this->negotiateCrypto);
            $this->pendingTlsStreams[$socketId] = [$watcherId, $socket];
        } else {
            $this->finalizeImport($socket);
        }
    }

    private function negotiateCrypto(Reactor $reactor, string $watcherId, $socket) {
        if ($handshake = @stream_socket_enable_crypto($socket, true)) {
            $socketId = (int) $socket;
            $reactor->cancel($watcherId);
            unset($this->pendingTlsStreams[$socketId]);
            $meta = stream_get_meta_data($socket);
            if (isset($meta['alpn_protocol']) && $meta['alpn_protocol'] === "h2") {
                // @TODO: Export socket to HTTP/2.0 handler
                // Since we haven't implemented h2 yet we'll send all clients to the
                // standard HTTP/1.1 readiness handler for now.
            }
            $this->finalizeImport($socket);
        } elseif ($handshake === false) {
            $this->failCryptoNegotiation($socket);
        }
    }

    private function failCryptoNegotiation($socket) {
        --$this->clientCount;
        $socketId = (int) $socket;
        list($watcherId) = $this->pendingTlsStreams[$socketId];
        $this->reactor->cancel($watcherId);
        unset($this->pendingTlsStreams[$socketId]);
        @fclose($socket);
    }

    private function close(Rfc7230Client $client) {
        $this->clientCount--;
        $this->clear($client);
        if (!$client->isDead) {
            @fclose($client->socket);
            $client->isDead = true;
        }
    }

    private function clear(Rfc7230Client $client) {
        $client->onUpgrade = null;
        $client->requestParser = null;
        $client->onWriteDrain = null;
        $this->reactor->cancel($client->readWatcher);
        $this->reactor->cancel($client->writeWatcher);
        $this->clearKeepAliveTimeout($client);
        unset($this->clients[$client->id]);
        if ($this->stopPromisor && $this->clientCount === 0) {
            $this->stopPromisor->succeed();
        }
    }

    private function export(Rfc7230Client $client): \Closure {
        $socket = $client->socket;
        $exportId = (int) $socket;
        $this->exports[$exportId] = $socket;
        $client->isDead = true;
        $client->isExported = true;
        $this->clear($client);

        return function() use ($exportId) { $this->clearExport($exportId); };
    }

    private function clearExport(int $exportId) {
        if (isset($this->exports[$exportId])) {
            unset($this->exports[$exportId]);
            $this->clientCount--;
        }
    }

    private function finalizeImport($socket) {
        $client = new Rfc7230Client;
        $client->id = (int) $socket;
        $client->socket = $socket;
        $client->isEncrypted = (bool) @stream_context_get_options($socket)["ssl"];
        $client->requestsRemaining = $this->options->maxRequests;

        $clientName = stream_socket_get_name($socket, true);
        $portStartPos = strrpos($clientName, ":");
        $client->clientAddr = substr($clientName, 0, $portStartPos);
        $client->clientPort = substr($clientName, $portStartPos + 1);

        $serverName = stream_socket_get_name($socket, false);
        $portStartPos = strrpos($serverName, ":");
        $client->serverAddr = substr($serverName, 0, $portStartPos);
        $client->serverPort = substr($serverName, $portStartPos + 1);
        $client->requestParser = new Rfc7230RequestParser([$this, "onParse"], $options = [
            "max_body_size" => $this->options->maxBodySize,
            "max_header_size" => $this->options->maxHeaderSize,
            "body_emit_size" => $this->options->ioGranularity,
            "cb_data" => $client
        ]);
        $client->readWatcher = $this->reactor->onReadable($socket, [$this, "onReadable"], $options = [
            "enable" => true,
            "cb_data" => $client,
        ]);
        $client->writeWatcher = $this->reactor->onWritable($socket, [$this, "onWritable"], $options = [
            "enable" => false,
            "cb_data" => $client,
        ]);

        $this->clients[$client->id] = $client;
        $this->renewKeepAliveTimeout($client);
    }

    /**
     * React to socket writability
     *
     * @param \Amp\Reactor $reactor
     * @param string $watcherId
     * @param resource $socket
     * @param \Aerys\Rfc7230Client $client
     * @return void
     */
    public function onWritable(Reactor $reactor, string $watcherId, $socket, $client) {
        $bytesWritten = @fwrite($socket, $client->writeBuffer);
        if ($bytesWritten === false) {
            if (!is_resource($socket) || @feof($socket)) {
                $client->isDead = true;
                $this->close($client);
            }
        } elseif ($bytesWritten === strlen($client->writeBuffer)) {
            $client->writeBuffer = "";
            $reactor->disable($watcherId);
            if ($client->onWriteDrain) {
                ($client->onWriteDrain)($client);
            }
        } else {
            $client->writeBuffer = substr($client->writeBuffer, $bytesWritten);
            $reactor->enable($watcherId);
        }
    }

    private function renewKeepAliveTimeout(Rfc7230Client $client) {
        $timeoutAt = $this->currentTime + $this->options->keepAliveTimeout;
        // DO NOT remove the call to unset(); it looks superfluous but it's not.
        // Keep-alive timeout entries must be ordered by value. This means that
        // it's not enough to replace the existing map entry -- we have to remove
        // it completely and push it back onto the end of the array to maintain the
        // correct order.
        unset($this->keepAliveTimeouts[$client->id]);
        $this->keepAliveTimeouts[$client->id] = $timeoutAt;
    }

    private function clearKeepAliveTimeout(Rfc7230Client $client) {
        unset($this->keepAliveTimeouts[$client->id]);
    }

    /**
     * React to socket readability
     *
     * @param \Amp\Reactor $reactor
     * @param string $watcherId
     * @param resource $socket
     * @param \Aerys\Rfc7230Client $client
     * @return void
     */
    public function onReadable(Reactor $reactor, string $watcherId, $socket, $client) {
        $data = @fread($socket, $this->options->ioGranularity);
        if ($data == "") {
            if (!is_resource($socket) || @feof($socket)) {
                $client->isDead = true;
                $this->close($client);
            }
            return;
        }

        // We only renew the keep-alive timeout if the client isn't waiting
        // for us to generate a response.
        if (empty($client->requestCycleQueue)) {
            $this->renewKeepAliveTimeout($client);
        }

        $client->requestParser->sink($data);
    }

    /**
     * React to parse events
     *
     * @param array $parseStruct
     * @param Aerys\Rfc7230Client $client
     * @return void
     */
    public function onParse(array $parseStruct, $client) {
        list($eventType, $parseResult, $errorStruct) = $parseStruct;
        switch ($eventType) {
            case Rfc7230RequestParser::RESULT:
                $this->onParsedMessageWithoutEntity($client, $parseResult);
                break;
            case Rfc7230RequestParser::ENTITY_HEADERS:
                $this->onParsedEntityHeaders($client, $parseResult);
                break;
            case Rfc7230RequestParser::ENTITY_PART:
                $this->onParsedEntityPart($client, $parseResult);
                break;
            case Rfc7230RequestParser::ENTITY_RESULT:
                $this->onParsedMessageWithEntity($client, $parseResult);
                break;
            case Rfc7230RequestParser::ERROR:
                $this->onParseError($client, $parseResult, $errorStruct);
                break;
            default:
                assert(false, "Unexpected Rfc7230RequestParser result code encountered");
        }
    }

    private function onParsedMessageWithoutEntity(Rfc7230Client $client, array $parseResult) {
        $requestCycle = $this->initializeRequestCycle($client, $parseResult);
        $this->clearKeepAliveTimeout($client);

        // Only respond if this request is at the front of the queue
        if ($client->requestCycleQueueSize === 1) {
            $this->respond($requestCycle);
        }
    }

    private function onParsedEntityPart(Rfc7230Client $client, array $parseResult) {
        $client->currentRequestCycle->bodyPromisor->update($parseResult["body"]);
    }

    private function onParsedEntityHeaders(Rfc7230Client $client, array $parseResult) {
        $requestCycle = $this->initializeRequestCycle($client, $parseResult);
        $requestCycle->bodyPromisor = new Deferred;
        $requestCycle->request->body = new BodyStream($requestCycle->bodyPromisor->promise());

        // Only respond if this request is at the front of the queue
        if ($client->requestCycleQueueSize === 1) {
            $this->respond($requestCycle);
        }
    }

    private function onParsedMessageWithEntity(Rfc7230Client $client, array $parseResult) {
        $client->currentRequestCycle->bodyPromisor->succeed();
        $client->currentRequestCycle->bodyPromisor = null;
        $this->clearKeepAliveTimeout($client);
        // @TODO Update trailer headers if present
        // We don't call respond() because we started the response when headers arrived
    }

    private function onParseError(Rfc7230Client $client, array $parseResult, array $errorStruct) {
        // @TODO how to handle parse error with entity body after request cycle already started?
        $this->clearKeepAliveTimeout($client);
        $requestCycle = $this->initializeRequestCycle($client, $parseResult);
        list($code, $msg) = $errorStruct;
        $application = function(Request $request, Response $response) use ($code, $msg) {
            $response->setStatus($code);
            $response->setHeader("Connection", "close");
            $response->end($body = $this->makeGenericBody($code, null, $msg));
        };

        $requestCycle->parseErrorResponder = $application;

        // Only respond if this request is at the front of the queue
        if ($client->requestCycleQueueSize === 1) {
            $this->respond($requestCycle);
        }
    }

    private function initializeRequestCycle(Rfc7230Client $client, array $parseResult): Rfc7230RequestCycle {
        $requestCycle = new Rfc7230RequestCycle;
        $requestCycle->client = $client;
        $client->requestsRemaining--;
        $client->currentRequestCycle = $requestCycle;
        $client->requestCycleQueue[] = $requestCycle;
        $client->requestCycleQueueSize++;

        $trace = $parseResult["trace"];
        $protocol = empty($parseResult["protocol"]) ? "1.0" : $parseResult["protocol"];
        $method = empty($parseResult["method"]) ? "?" : $parseResult["method"];
        if ($this->options->normalizeMethodCase) {
            $method = strtoupper($method);
        }
        $uri = empty($parseResult["uri"]) ? "/" : $parseResult["uri"];
        $headers = empty($parseResult["headers"]) ? [] : $parseResult["headers"];
        foreach ($headers as $field => $value) {
            $headers[$field] = isset($value[1]) ? implode(',', $value) : $value[0];
        }

        $request = new Request;
        $requestCycle->request = $request;

        $request->debug = $this->options->debug;
        $request->locals = new \StdClass;
        $request->time = $this->currentTime;
        $request->remaining = $client->requestsRemaining;
        $request->isEncrypted = $client->isEncrypted;
        $request->trace = $trace;
        $request->protocol = $protocol;
        $request->method = $method;
        $request->headers = $headers;
        $request->body = $this->bodyNull;
        $request->serverPort = $client->serverPort;
        $request->serverAddr = $client->serverAddr;
        $request->clientPort = $client->clientPort;
        $request->clientAddr = $client->clientAddr;
        $request->uriRaw = $uri;
        if (stripos($uri, "http://") === 0 || stripos($uri, "https://") === 0) {
            extract(parse_url($uri, EXTR_PREFIX_ALL, "uri"));
            $request->uriHost = $uri_host;
            $request->uriPort = $uri_port;
            $request->uriPath = $uri_path;
            $request->uriQuery = $uri_query;
            $request->uri = isset($uri_query) ? "{$uri_path}?{$uri_query}" : $uri_path;
        } elseif ($qPos = strpos($uri, '?')) {
            $request->uriQuery = substr($uri, $qPos + 1);
            $request->uriPath = substr($uri, 0, $qPos);
            $request->uri = "{$request->uriPath}?{$request->uriQuery}";
        } else {
            $request->uri = $request->uriPath = $uri;
        }

        if ($request->uriQuery == "") {
            $request->query = [];
        } else {
            parse_str($request->uriQuery, $request->query);
        }

        $requestCycle->responseWriter = $this->generateResponseWriter($requestCycle->client);
        $requestCycle->responseFilter = null; // Created at app call-time
        $requestCycle->badFilterKeys = [];

        if ($vhost = $this->vhostGroup->selectHost($request)) {
            $requestCycle->isVhostValid = true;
        } else {
            $requestCycle->isVhostValid = false;
            $vhost = $this->vhostGroup->getDefaultHost();
        }
        $requestCycle->vhost = $vhost;
        $request->serverName = ($vhost->hasName())
            ? $vhost->getName()
            : $requestCycle->client->serverAddr
        ;

        // @TODO Handle 100 Continue responses
        // $expectsContinue = empty($headers["EXPECT"]) ? false : stristr($headers["EXPECT"], "100-continue");

        return $requestCycle;
    }

    private function respond(Rfc7230RequestCycle $requestCycle) {
        if ($requestCycle->parseErrorResponder) {
            $application = $requestCycle->parseErrorResponder;
        } elseif (!$requestCycle->isVhostValid) {
            $application = [$this, "sendPreAppInvalidHostResponse"];
        } elseif (!in_array($requestCycle->request->method, $this->options->allowedMethods)) {
            $application = [$this, "sendPreAppMethodNotAllowedResponse"];
        } elseif ($requestCycle->request->method === "TRACE") {
            $application = [$this, "sendPreAppTraceResponse"];
        } elseif ($requestCycle->request->method === "OPTIONS" && $uri->raw === "*") {
            $application = [$this, "sendPreAppOptionsResponse"];
        } else {
            $application = $requestCycle->vhost->getApplication();
        }

        /*
        // @TODO This check needs to be moved to the parser
        if ($this->options->requireBodyLength && empty($requestCycle->request->headers["CONTENT-LENGTH"])) {
            $application = function() {
                $response->setStatus(HTTP_STATUS["LENGTH_REQUIRED"])
                $response->setReason(HTTP_REASON[HTTP_STATUS["LENGTH_REQUIRED"]])
                $response->setHeader("Connection", "close")
                $response->end();
            };
        }
        */

        $this->tryApplication($requestCycle, $application);
    }

    private function sendPreAppInvalidHostResponse(Request $request, Response $response) {
        $status = HTTP_STATUS["BAD_REQUEST"];
        $response->setStatus($status);
        $response->setReason("Bad Request: Invalid Host");
        $response->setHeader("Connection", "close");
        $response->end($body = $this->makeGenericBody($status));
    }

    private function sendPreAppMethodNotAllowedResponse(Request $request, Response $response) {
        $status = HTTP_STATUS["METHOD_NOT_ALLOWED"];
        $response->setStatus($status);
        $response->setHeader("Connection", "close");
        $response->setHeader("Allow", implode(",", $this->options->allowedMethods));
        $response->end($body = $this->makeGenericBody($status));
    }

    private function sendPreAppTraceResponse(Request $request, Response $response) {
        $response->setStatus(HTTP_STATUS["OK"]);
        $response->setHeader("Content-Type", "message/http");
        $response->end($body = $request->trace);
    }

    private function sendPreAppOptionsResponse(Request $request, Response $response) {
        $response->setStatus(HTTP_STATUS["OK"]);
        $response->setHeader("Allow", implode(",", $this->options->allowedMethods));
        $response->end($body = null);
    }

    private function initializeResponseFilter(Rfc7230RequestCycle $requestCycle, Request $userRequest): Filter {
        $try = [$this->startResponseFilter, $this->genericResponseFilter];

        if ($userFilters = $requestCycle->vhost->getFilters()) {
            $try = array_merge($try, array_values($userFilters));
        }
        if ($requestCycle->request->method === "HEAD") {
            $try[] = $this->headResponseFilter;
        }
        if ($this->options->deflateEnable) {
            $try[] = $this->deflateResponseFilter;
        }
        if ($requestCycle->request->protocol === "1.1") {
            $try[] = $this->chunkResponseFilter;
        }
        if ($this->options->outputBufferSize > 0) {
            $try[] = $this->bufferResponseFilter;
        }

        $filters = [];
        foreach ($try as $key => $filter) {
            try {
                $result = ($filter)($userRequest);
                if ($result instanceof \Generator && $result->valid()) {
                    $filters[] = $result;
                }
            } catch (\BaseException $e) {
                if (!in_array($key, $requestCycle->badFilterKeys)) {
                    throw new FilterException($e, $key);
                }
            }
        }

        if ($requestCycle->badFilterKeys) {
            $filters = array_diff_key($filters, array_flip($requestCycle->badFilterKeys));
        }

        return ($requestCycle->responseFilter = new Filter($filters));
    }

    private function generateResponseWriter(Rfc7230Client $client): \Generator {
        $messageBuffer = "";
        $headerEndPos = null;
        $hasThrownClientException = false;

        do {
            if ($client->isDead && !$hasThrownClientException) {
                $hasThrownClientException = true;
                throw new ClientException;
            }
            $messageBuffer .= ($part = yield);
        } while (isset($part) && ($headerEndPos = \strpos($messageBuffer, "\r\n\r\n")) === false);

        if (isset($headerEndPos)) {
            $startLineAndHeaders = \substr($messageBuffer, 0, $headerEndPos + 4);
            $headers = \explode("\r\n", $startLineAndHeaders, 2)[1];
            $status = substr($messageBuffer, 9, 3);
            $client->shouldClose = headerMatches($headers, "Connection", "close");
        } else {
            $status = null;
            $client->shouldClose = true;
            // We never received the full headers prior to the END $part indicator.
            // This is a clear error by a filter ... all we can do is write what we have.
        }

        // We can't upgrade the connection if closing or not sending a 101
        if ($client->shouldClose || $status !== "101") {
            $client->onUpgrade = null;
        }

        $toWrite = $messageBuffer;

        do {
            if ($client->isDead && !$hasThrownClientException) {
                $hasThrownClientException = true;
                throw new ClientException;
            }

            $client->writeBuffer .= $toWrite;
            $this->onWritable($this->reactor, $client->writeWatcher, $client->socket, $client);
        } while (($toWrite = yield) !== null);

        if ($client->writeBuffer == "") {
            $this->onCompletedResponse($client);
        } else {
            $client->onWriteDrain = $this->onCompletedResponse;
            $this->onWritable($this->reactor, $client->writeWatcher, $client->socket, $client);
        }
    }

    private function onCompletedResponse(Rfc7230Client $client) {
        if ($client->onUpgrade) {
            $this->reactor->immediately($this->exporter, $options = ["cb_data" => $client]);
        } elseif ($client->shouldClose) {
            $this->close($client);
        } elseif (--$client->requestCycleQueueSize) {
            array_shift($client->requestCycleQueue);
            $requestCycle = current($client->requestCycleQueue);
            $this->respond($requestCycle);
        } else {
            $client->requestCycleQueue = [];
            $this->renewKeepAliveTimeout($client);
        }
    }

    private function tryApplication(Rfc7230RequestCycle $requestCycle, callable $application) {
        try {
            $userRequest = clone $requestCycle->request;
            $response = $requestCycle->response = new StandardResponse(
                $this->initializeResponseFilter($requestCycle, $userRequest),
                $requestCycle->responseWriter,
                $requestCycle->client
            );

            $result = ($application)($userRequest, $response);

            if ($result instanceof \Generator) {
                $promise = resolve($result, $this->reactor);
                $promise->when($this->onCoroutineAppResolve, $requestCycle);
            } elseif ($response->state() & Response::STARTED) {
                $response->end();
            } else {
                $status = HTTP_STATUS["NOT_FOUND"];
                $subHeading = "Requested: {$requestCycle->request->uri}";
                $response->setStatus($status);
                $response->end($this->makeGenericBody($status, $subHeading));
            }
        } catch (ClientException $error) {
            // Do nothing -- responder actions aren't required to catch this
        } catch (\BaseException $error) {
            $this->onApplicationError($error, $requestCycle);
        }
    }

    private function onCoroutineAppResolve($error, $result, $requestCycle) {
        if (empty($error)) {
            if ($requestCycle->client->isExported || $requestCycle->client->isDead) {
                return;
            } elseif ($requestCycle->response->state() & Response::STARTED) {
                $requestCycle->response->end();
            } else {
                $status = HTTP_STATUS["NOT_FOUND"];
                $subHeading = "Requested: {$requestCycle->request->uri}";
                $requestCycle->response->setStatus($status);
                $requestCycle->response->end($this->makeGenericBody($status, $subHeading));
            }
        } elseif (!$error instanceof ClientException) {
            // Ignore uncaught ClientException -- applications aren't required to catch this
            $this->onApplicationError($error, $requestCycle);
        }
    }

    private function onApplicationError(\BaseException $error, Rfc7230RequestCycle $requestCycle) {
        $client = $requestCycle->client;
        if ($client->isDead || $client->isExported) {
            // Responder actions may catch the initial ClientException and continue
            // doing further work. If an error arises at this point we can end up
            // here and our only option is to log the error.
            error_log($error->__toString());
            return;
        }

        if (!$this->options->debug) {
            // Only log if we aren't in debug mode
            error_log($error->__toString());
        }

        // This occurs if filter generators error upon initial invocation.
        // In this case we need to keep trying to create the filter until
        // we no longer get errors.
        while (empty($requestCycle->response)) {
            try {
                $requestCycle->response = new StandardResponse(
                    $this->initializeResponseFilter($requestCycle),
                    $requestCycle->responseWriter,
                    $client
                );
            } catch (FilterException $e) {
                $requestCycle->badFilterKeys[] = $e->getFilterKey();
            }
        }

        // If response output has already started we can't proceed any further.
        if ($requestCycle->response->state() & Response::STARTED) {
            error_log($error->__toString());
            $this->close($client);
            return;
        }

        if ($error instanceof FilterException) {
            while ($error instanceof FilterException) {
                // If a user filter caused the error we recreate the Filter object
                // excluding the bad filter and ninja-insert it into the response.
                // Filters added to the "badFilterKeys" array will be excluded from
                // the resulting filter inside initializeResponseFilter().
                $requestCycle->badFilterKeys[] = $error->getFilterKey();
                $filter = $this->initializeResponseFilter($requestCycle);
                $filterNinja = function($filter) { $this->filter = $filter; };
                $filterNinja->call($requestCycle->response, $filter);

                try {
                    $this->sendErrorResponse($error, $requestCycle);
                } catch (FilterException $error) {
                    // Seriously, bro? Keep trying until no offending filters remain ...
                    if (!$this->options->debug) {
                        error_log($error->__toString());
                    }
                }
            }
        } else {
            $this->sendErrorResponse($error, $requestCycle);
        }
    }

    private function sendErrorResponse(\BaseException $error, Rfc7230RequestCycle $requestCycle) {
        $status = HTTP_STATUS["INTERNAL_SERVER_ERROR"];
        $subHeading = "Requested: {$requestCycle->request->uri}";
        $msg = ($this->options->debug)
            ? $this->makeDebugMessage($error, $requestCycle->request)
            : "<p>Something went wrong ...</p>"
        ;
        $body = $this->makeGenericBody($status, $subHeading, $msg);
        $requestCycle->response->setStatus(HTTP_STATUS["INTERNAL_SERVER_ERROR"]);
        $requestCycle->response->setHeader("Connection", "close");
        $requestCycle->response->end($body);
    }

    private function makeDebugMessage(\BaseException $error, Request $request): string {
        $vars = [
            "time"          => $request->time,
            "debug"         => ($request->debug ? "true" : "false"),
            "isEncrypted"   => ($request->isEncrypted ? "true" : "false"),
            "serverAddr"    => $request->serverAddr,
            "serverPort"    => $request->serverPort,
            "serverName"    => $request->serverName,
            "clientAddr"    => $request->clientAddr,
            "clientPort"    => $request->clientPort,
            "method"        => $request->method,
            "protocol"      => $request->protocol,
            "uri"           => $request->uriRaw,
            "headers"       => $request->headers,
        ];

        $map = function($s) { return substr($s, 4); };
        $vars = implode("\n", array_map($map, array_slice(explode("\n", print_r($vars, true)), 2, -2)));

        $msg[] = "<pre>{$error}</pre>";
        $msg[] = "\n<hr/>\n";
        $msg[] = "<pre>{$request->trace}</pre>";
        $msg[] = "\n<hr/>\n";
        $msg[] = "<pre>{$vars}</pre>";
        $msg[] = "\n";

        return implode($msg);
    }

    private function makeGenericBody(int $status, string $subHeading = null, string $msg = null): string {
        $serverToken = $this->options->sendServerToken ? (SERVER_TOKEN . " @ ") : "";
        $reason = HTTP_REASON[$status] ?? "";
        $subHeading = isset($subHeading) ? "<h3>{$subHeading}</h3>" : "";
        $msg = isset($msg) ? "{$msg}\n" : "";

        return sprintf(
            "<html>\n<body>\n<h1>%d %s</h1>\n%s\n<hr/>\n<em>%s%s</em>\n<br/><br/>\n%s</body>\n</html>",
            $status,
            $reason,
            $subHeading,
            $serverToken,
            $this->currentHttpDate,
            $msg
        );
    }

    private function startResponseFilter(Request $request): \Generator {
        $protocol = $request->protocol;
        $requestsRemaining = $request->remaining;
        $message = "";
        $headerEndOffset = null;

        do {
            $message .= ($part = yield);
        } while ($part !== Filter::END && ($headerEndOffset = \strpos($message, "\r\n\r\n")) === false);
        if (!isset($headerEndOffset)) {
            return $message;
        }

        $options = $this->options;
        $startLineAndHeaders = substr($message, 0, $headerEndOffset);
        $entity = substr($message, $headerEndOffset + 4);
        list($startLine, $headers) = explode("\r\n", $startLineAndHeaders, 2);
        $startLineParts = explode(" ", $startLine);
        $status = $startLineParts[1];
        $reason = $startLineParts[2] ?? "";

        if (empty($reason) && $options->autoReasonPhrase) {
            $reason = HTTP_REASON[$status] ?? "";
        }
        if ($options->sendServerToken) {
            $headers = setHeader($headers, "Server", SERVER_TOKEN);
        }

        $contentLength = getHeader($headers, "__Aerys-Entity-Length");
        $headers = removeHeader($headers, "__Aerys-Entity-Length");

        if ($contentLength === "@") {
            $hasContent = false;
            $shouldClose = ($protocol === "1.0");
        } elseif ($contentLength !== "*") {
            $hasContent = true;
            $shouldClose = false;
            $headers = setHeader($headers, "Content-Length", $contentLength);
        } elseif ($protocol === "1.1") {
            $hasContent = true;
            $shouldClose = false;
            $headers = setHeader($headers, "Transfer-Encoding", "chunked");
        } else {
            $hasContent = true;
            $shouldClose = true;
        }

        if ($hasContent && $status >= 200 && ($status < 300 || $status >= 400)) {
            $type = getHeader($headers, "Content-Type") ?? $options->defaultContentType;
            if (\stripos($type, "text/") === 0 && \stripos($type, "charset=") === false) {
                $type .= "; charset={$options->defaultTextCharset}";
            }
            $headers = setHeader($headers, "Content-Type", $type);
        }

        if ($shouldClose || $this->stopPromisor || $request->remaining === 0) {
            $headers = setHeader($headers, "Connection", "close");
        } else {
            $keepAlive = "timeout={$options->keepAliveTimeout}, max={$requestsRemaining}";
            $headers = setHeader($headers, "Keep-Alive", $keepAlive);
        }

        $headers = setHeader($headers, "Date", $this->currentHttpDate);
        $headers = \trim($headers);

        return "HTTP/{$protocol} {$status} {$reason}\r\n{$headers}\r\n\r\n{$entity}";
    }

    private function genericResponseFilter(Request $request): \Generator {
        $message = "";
        $headerEndOffset = null;

        do {
            $message .= ($part = yield);
        } while ($part !== Filter::END && ($headerEndOffset = \strpos($message, "\r\n\r\n")) === false);

        // We received the Filter::END signal before full headers were received.
        // There's nothing we can do but pass along what we did receive.
        if (!isset($headerEndOffset)) {
            return $message;
        }

        $startLineAndHeaders = substr($message, 0, $headerEndOffset);
        list($startLine, $headers) = explode("\r\n", $startLineAndHeaders, 2);
        if (empty(getHeader($headers, "Aerys-Generic-Response"))) {
            return $message;
        }

        $startLineParts = explode("\x20", $startLine, 2);
        $status = (int) $startLineParts[1];
        $subHeading = "Requested: {$request->uri}";
        $body = $this->makeGenericBody($status, $subHeading);
        $headers = removeHeader($headers, "Aerys-Generic-Response");
        $headers = removeHeader($headers, "Transfer-Encoding");
        $headers = setHeader($headers, "Content-Length", strlen($body));
        $headers = trim($headers);

        return "{$startLine}\r\n{$headers}\r\n\r\n{$body}";
    }

    private function headResponseFilter(Request $request): \Generator {
        $message = "";
        $headerEndOffset = null;

        do {
            $message .= ($part = yield);
        } while ($part !== Filter::END && ($headerEndOffset = \strpos($message, "\r\n\r\n")) === false);

        // We received the Filter::END signal before full headers were received.
        // There's nothing we can do but pass along what we did receive.
        if (!isset($headerEndOffset)) {
            return $message;
        }

        // Pass on the start line and headers
        yield \substr($message, 0, $headerEndOffset + 4);

        // Swallow all entity body data
        while (yield !== Filter::END);
    }

    private function deflateResponseFilter(Request $request): \Generator {
        if (empty($request->headers["ACCEPT-ENCODING"])) {
            return;
        }

        // @TODO Perform a more sophisticated check for gzip acceptance.
        // This check isn't technically correct as the gzip parameter
        // could have a q-value of zero indicating "never accept gzip."
        if (stripos($request->headers["ACCEPT-ENCODING"], "gzip") === false) {
            return;
        }

        // @TODO We have the ability to support DEFLATE and RAW encoding as well. Should we?
        $mode = \ZLIB_ENCODING_GZIP;
        $message = "";
        $headerEndOffset = null;

        do {
            $message .= ($tmp = yield);
        } while ($tmp !== Filter::END && ($headerEndOffset = \strpos($message, "\r\n\r\n")) === false);

        // We received the Filter::END signal before full headers were received.
        // There's nothing we can do but pass along what we did receive.
        if (!isset($headerEndOffset)) {
            return $message;
        }

        $startLineAndHeaders = \substr($message, 0, $headerEndOffset + 4);
        list($startLine, $headers) = \explode("\r\n", $startLineAndHeaders, 2);

        // Require a Content-Type header
        if (!$contentType = getHeader($headers, "Content-Type")) {
            return $message;
        }

        // Require a text/* mime Content-Type
        // @TODO Allow option to configure which mime prefixes/types may be compressed
        if (stripos($contentType, "text/") !== 0) {
            return $message;
        }

        $bodyBuffer = (string) \substr($message, $headerEndOffset + 4);
        $minBodySize = $this->options->deflateMinimumLength;
        $contentLength = getHeader($headers, "Content-Length");

        if (!isset($contentLength)) {
            // Wait until we know there's enough stream data to compress before proceeding.
            // If we receive a FLUSH or an END signal before we have enough then we won't
            // use any compression.
            while (!isset($bodyBuffer[$minBodySize])) {
                $bodyBuffer .= ($tmp = yield);
                if ($tmp === Filter::FLUSH || $tmp === Filter::END) {
                    return $startLineAndHeaders . $bodyBuffer;
                }
            }
        } elseif ($contentLength < $minBodySize || $contentLength === "0") {
            // If the Content-Length is too small we can't compress it. Return
            // everything we've received unmodified.
            return $startLineAndHeaders . $bodyBuffer;
        }

        if (($resource = \deflate_init($mode)) === false) {
            return $startLineAndHeaders . $bodyBuffer;
        }

        // Once we decide to compress output we no longer know what the
        // final Content-Length will be. We need to update our headers
        // according to the HTTP protocol in use to reflect this.
        $headers = removeHeader($headers, "Content-Length");
        $headers = (substr($startLine, 5, 3) === "1.1")
            ? setHeader($headers, "Transfer-Encoding", "chunked")
            : setHeader($headers, "Connection", "close");

        $headers = setHeader($headers, "Content-Encoding", "gzip");
        $headers = trim($headers);

        // The first "deflated" string we yield contains our updated headers.
        $deflated = "{$startLine}\r\n{$headers}\r\n\r\n";

        // Don't wait for garbage collection because the subsequent loop could run indefinitely
        unset(
            $message,
            $headerEndOffset,
            $tmp,
            $startLineAndHeaders,
            $startLine,
            $contentLength,
            $contentType,
            $minBodySize,
            $headers
        );

        $minFlushOffset = $this->options->deflateBufferSize;

        while (($uncompressed = yield $deflated) !== Filter::END) {
            $bodyBuffer .= $uncompressed;

            if ($uncompressed === Filter::FLUSH) {
                if ($bodyBuffer === "") {
                    // If we don't have any buffered data there's nothing to flush
                    $deflated = null;
                    continue;
                }
                if (($deflated = \deflate_add($resource, $bodyBuffer, \ZLIB_SYNC_FLUSH)) === false) {
                    return;
                }
                $bodyBuffer = "";
            } elseif (isset($bodyBuffer[$minFlushOffset])) {
                // We have enough data to dump into our deflate resource
                $deflated = \deflate_add($resource, $bodyBuffer);
                $bodyBuffer = "";
                if ($deflated === false) {
                    return;
                }
            } else {
                $deflated = Filter::NEEDS_MORE_DATA;
            }
        }

        if (($deflated = \deflate_add($resource, $bodyBuffer, \ZLIB_FINISH)) === false) {
            return;
        }

        return $deflated;
    }

    private function chunkResponseFilter(Request $request): \Generator {
        $message = "";
        $headerEndOffset = null;

        do {
            $message .= ($part = yield);
        } while ($part !== Filter::END && ($headerEndOffset = \strpos($message, "\r\n\r\n")) === false);

        // We received the Filter::END signal before full headers were received.
        // There's nothing we can do but pass along what we did receive.
        if (!isset($headerEndOffset)) {
            return $message;
        }

        $startLineAndHeaders = \substr($message, 0, $headerEndOffset + 4);
        $headers = explode("\r\n", $startLineAndHeaders, 2)[1];
        if (!headerMatches($headers, "Transfer-Encoding", "chunked")) {
            // If the headers don't specify that we should chunk then don't do it.
            return $message;
        }

        $chunk = $startLineAndHeaders;
        $bodyBuffer = (string) \substr($message, $headerEndOffset + 4);
        $bufferSize = $this->options->chunkBufferSize;

        // Don't wait for garbage collection because the subsequent loop can run indefinitely
        unset($message, $headerEndOffset, $part, $startLineAndHeaders, $headers);

        while (($unchunked = yield $chunk) !== Filter::END) {
            $bodyBuffer .= $unchunked;
            if (isset($bodyBuffer[$bufferSize]) || ($unchunked === Filter::FLUSH && $bodyBuffer != "")) {
                $chunk = \dechex(\strlen($bodyBuffer)) . "\r\n{$bodyBuffer}\r\n";
                $bodyBuffer = "";
            } else {
                $chunk = Filter::NEEDS_MORE_DATA;
            }
        }

        $chunk = isset($bodyBuffer[0])
            ? (\dechex(\strlen($bodyBuffer)) . "\r\n{$bodyBuffer}\r\n0\r\n\r\n")
            : "0\r\n\r\n"
        ;

        return $chunk;
    }

    private function bufferResponseFilter(Request $request): \Generator {
        $bufferSize = $this->options->outputBufferSize;
        $out = Filter::NEEDS_MORE_DATA;
        $buffer = "";

        while (($in = yield $out) !== Filter::END) {
            $buffer .= $in;
            if ($in === Filter::FLUSH || isset($out[$bufferSize])) {
                $out = $buffer;
                $buffer = "";
            } else {
                $out = Filter::NEEDS_MORE_DATA;
            }
        }

        return $buffer;
    }

    private function updateTime() {
        // Date string generation is (relatively) expensive. Since we only need HTTP
        // dates at a granularity of one second we're better off to generate this
        // information once per second and cache it.
        $now = (int) round(microtime(1));
        $this->currentTime = $now;
        $this->currentHttpDate = gmdate("D, d M Y H:i:s", $now) . " GMT";
    }

    /**
     * React to server state changes
     *
     * @param SplSubject $subject The notifying Aerys\Server instance
     * @return Amp\Promise
     */
    public function update(\SplSubject $subject): Promise {
        switch ($subject->state()) {
            case Server::STARTED:
                $this->keepAliveWatcher = $this->reactor->repeat(function() {
                    $now = $this->currentTime;
                    foreach ($this->keepAliveTimeouts as $id => $expiresAt) {
                        if ($now > $expiresAt) {
                            $this->close($this->clients[$id]);
                        } else {
                            break;
                        }
                    }
                }, 1000);
                $this->timeUpdateWatcher = $this->reactor->repeat($this->updateTime, 1000);
                $this->updateTime();
                $promise = new Success;
                break;
            case Server::STOPPING:
                foreach ($this->pendingTlsStreams as list(, $socket)) {
                    $this->failCryptoNegotiation($socket);
                }
                $this->stopPromisor = new Deferred;
                $promise = $this->stopPromisor->promise();
                $promise->when(function() {
                    $this->reactor->cancel($this->keepAliveWatcher);
                    $this->keepAliveWatcher = null;
                    $this->stopPromisor = null;
                });
                break;
            case Server::STOPPED:
                $this->reactor->cancel($this->timeUpdateWatcher);
                $this->timeUpdateWatcher = null;
                $promise = new Success;
                break;
            default:
                $promise = new Success;
                break;
        }

        $this->serverInfo = $subject->inspect();

        return $promise;
    }
}
