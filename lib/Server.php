<?php

namespace Aerys;

use \Generator;

use Amp\{
    Struct,
    Reactor,
    Promise,
    Success,
    Failure,
    Deferred,
    function resolve,
    function timeout,
    function any,
    function all
};

class Server implements \SplSubject {
    use Struct;

    const STOPPED  = 0;
    const STARTING = 1;
    const STARTED  = 2;
    const STOPPING = 3;

    private $state = self::STOPPED;
    private $reactor;
    private $options;
    private $vhostContainer;
    private $logger;
    private $timeContext;
    private $observers;
    private $decrementer;
    private $acceptWatcherIds = [];
    private $boundServers = [];
    private $pendingTlsStreams = [];
    private $clients = [];
    private $clientCount = 0;
    private $keepAliveTimeouts = [];
    private $exporter;
    private $nullBody;
    private $stopPromisor;
    private $httpDriver;

    // private callables that we pass to external code //
    private $onAcceptable;
    private $negotiateCrypto;
    private $onReadable;
    private $onWritable;
    private $startWrite;
    private $onParse;
    private $onCoroutineAppResolve;
    private $onCompletedData;

    public function __construct(
        Reactor $reactor,
        Options $options,
        VhostContainer $vhostContainer,
        Logger $logger,
        TimeContext $timeContext
    ) {
        $this->reactor = $reactor;
        $this->options = $options;
        $this->vhostContainer = $vhostContainer;
        $this->logger = $logger;
        $this->timeContext = $timeContext;
        $this->observers = new \SplObjectStorage;
        $this->observers->attach($timeContext);
        $this->decrementer = function() {
            if ($this->clientCount) {
                $this->clientCount--;
            }
        };
        $this->timeContext->use($this->makePrivateCallable("timeoutKeepAlives"));
        $this->nullBody = new NullBody;
        $this->exporter = $this->makePrivateCallable("export");

        // private callables that we pass to external code //
        $this->onAcceptable = $this->makePrivateCallable("onAcceptable");
        $this->negotiateCrypto = $this->makePrivateCallable("negotiateCrypto");
        $this->onReadable = $this->makePrivateCallable("onReadable");
        $this->onWritable = $this->makePrivateCallable("onWritable");
        $this->startWrite = $this->makePrivateCallable("startWrite");
        $this->onParse = $this->makePrivateCallable("onParse");
        $this->onCoroutineAppResolve = $this->makePrivateCallable("onCoroutineAppResolve");
        $this->onCompletedData = $this->makePrivateCallable("onCompletedData");

        $this->initHttp(new Http1Driver($options, $this->onParse, $this->startWrite));
        //$this->initHttp(new Http2Driver($options, $this->onParse, $this->startWrite));
    }

    private function initHttp(HttpDriver $http) {
        foreach ($http->versions() as $version) {
            $this->httpDriver[$version] = $http;
        }
    }

    /**
     * Retrieve the current server state
     *
     * @return int
     */
    public function state(): int {
        return $this->state;
    }

    /**
     * Retrieve a server option value
     *
     * @param string $option The option to retrieve
     * @throws \DomainException on unknown option
     * @return mixed
     */
    public function getOption(string $option) {
        return $this->options->{$option};
    }

    /**
     * Attach an observer
     *
     * @param \SplObserver $observer
     * @return void
     */
    public function attach(\SplObserver $observer) {
        $this->observers->attach($observer);
    }

    /**
     * Detach an Observer
     *
     * @param \SplObserver $observer
     * @return void
     */
    public function detach(\SplObserver $observer) {
        $this->observers->detach($observer);
    }

    /**
     * Notify observers of a server state change
     *
     * Resolves to an indexed any() promise combinator array.
     *
     * @return \Amp\Promise
     */
    public function notify(): Promise {
        $promises = [];
        foreach ($this->observers as $observer) {
            $promises[] = $observer->update($this);
        }

        $promise = any($promises);
        $promise->when(function($error, $result) {
            // $error is always empty because an any() combinator promise never fails.
            // Instead we check the error array at index zero in the two-item amy() $result
            // and log as needed.
            list($observerErrors) = $result;
            foreach ($observerErrors as $error) {
                $this->logger->error($error);
            }
        });

        return $promise;
    }

    /**
     * Start the server
     *
     * @return \Amp\Promise
     */
    public function start(): Promise {
        try {
            switch ($this->state) {
                case self::STOPPED:
                    if ($this->vhostContainer->count() === 0) {
                        return new Failure(new \LogicException(
                            "Cannot start: no virtual hosts registered in composed VhostContainer"
                        ));
                    }
                    return resolve($this->doStart(), $this->reactor);
                case self::STARTING:
                    return new Failure(new \LogicException(
                        "Cannot start server: already STARTING"
                    ));
                case self::STARTED:
                    return new Failure(new \LogicException(
                        "Cannot start server: already STARTED"
                    ));
                case self::STOPPING:
                    return new Failure(new \LogicException(
                        "Cannot start server: already STOPPING"
                    ));
                default:
                    return new Failure(new \LogicException(
                        sprintf("Unexpected server state encountered: %s", $this->state)
                    ));
            }
        } catch (\BaseException $uncaught) {
            return new Failure($uncaught);
        }
    }

    private function doStart(): Generator {
        assert($this->logDebug("starting"));

        $addrCtxMap = $this->generateBindableAddressContextMap();
        foreach ($addrCtxMap as $address => $context) {
            $serverName = substr(str_replace('0.0.0.0', '*', $address), 6);
            $this->boundServers[$serverName] = $this->bind($address, $context);
        }

        $this->state = self::STARTING;
        $notifyResult = yield $this->notify();
        if ($hadErrors = (bool)$notifyResult[0]) {
            yield from $this->doStop();
            throw new \RuntimeException(
                "Server::STARTING observer initialization failure"
            );
        }

        $this->state = self::STARTED;

        $notifyResult = yield $this->notify();
        if ($hadErrors = $notifyResult[0]) {
            yield from $this->doStop();
            throw new \RuntimeException(
                "Server::STARTED observer initialization failure"
            );
        }

        assert($this->logDebug("started"));

        foreach ($this->boundServers as $serverName => $server) {
            $this->acceptWatcherIds[$serverName] = $this->reactor->onReadable($server, $this->onAcceptable);
            $this->logger->info("Listening on {$serverName}");
        }
    }

    private function generateBindableAddressContextMap() {
        $addrCtxMap = [];
        $addresses = $this->vhostContainer->getBindableAddresses();
        $tlsBindings = $this->vhostContainer->getTlsBindingsByAddress();
        $backlogSize = $this->options->socketBacklogSize;
        $shouldReusePort = !$this->options->debug;

        foreach ($addresses as $address) {
            $context = stream_context_create(["socket" => [
                "backlog"      => $backlogSize,
                "so_reuseport" => $shouldReusePort,
            ]]);
            if (isset($tlsBindings[$address])) {
                stream_context_set_option($context, ["ssl" => $tlsBindings[$address]]);
            }
            $addrCtxMap[$address] = $context;
        }

        return $addrCtxMap;
    }

    private function bind(string $address, $context) {
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        if (!$socket = stream_socket_server($address, $errno, $errstr, $flags, $context)) {
            throw new \RuntimeException(
                sprintf(
                    "Failed binding socket on %s: [Err# %s] %s",
                    $address,
                    $errno,
                    $errstr
                )
            );
        }

        return $socket;
    }

    private function onAcceptable(Reactor $reactor, string $watcherId, $server) {
        if (!$client = @stream_socket_accept($server, $timeout = 0, $peerName)) {
            return;
        }

        if ($this->clientCount++ === $this->options->maxConnections) {
            assert($this->logDebug("client denied: too many existing connections"));
            $this->clientCount--;
            @fclose($client);
            return;
        }

        assert($this->logDebug("accept {$peerName}"));

        stream_set_blocking($client, false);
        $contextOptions = stream_context_get_options($client);
        if (isset($contextOptions["ssl"])) {
            $clientId = (int) $client;
            $watcherId = $this->reactor->onReadable($client, $this->negotiateCrypto, $options = [
                "cb_data" => $peerName,
            ]);
            $this->pendingTlsStreams[$clientId] = [$watcherId, $client];
        } else {
            $this->importClient($client, $peerName, $this->httpDriver["1.1"]);
        }
    }

    private function negotiateCrypto(Reactor $reactor, string $watcherId, $socket, string $peerName) {
        if ($handshake = @stream_socket_enable_crypto($socket, true)) {
            $socketId = (int) $socket;
            $reactor->cancel($watcherId);
            unset($this->pendingTlsStreams[$socketId]);
            $meta = stream_get_meta_data($socket)["crypto"];
            $isH2 = (isset($meta["alpn_protocol"]) && $meta["alpn_protocol"] === "h2");
            assert($this->logDebug(sprintf("crypto negotiated %s%s", ($isH2 ? "(h2) " : ""), $peerName)));
            $this->importClient($socket, $peerName, $this->httpDriver[$isH2 ? "2.0" : "1.1"]);
        } elseif ($handshake === false) {
            assert($this->logDebug("crypto handshake error {$peerName}"));
            $this->failCryptoNegotiation($socket);
        }
    }

    private function failCryptoNegotiation($socket) {
        $this->clientCount--;
        $socketId = (int) $socket;
        list($watcherId) = $this->pendingTlsStreams[$socketId];
        $this->reactor->cancel($watcherId);
        unset($this->pendingTlsStreams[$socketId]);
        @fclose($socket);
    }

    /**
     * Stop the server
     *
     * @return \Amp\Promise
     */
    public function stop(): Promise {
        switch ($this->state) {
            case self::STARTED:
                $stopPromise = resolve($this->doStop(), $this->reactor);
                return timeout($stopPromise, $this->options->shutdownTimeout, $this->reactor);
            case self::STOPPED:
                return new Success;
            case self::STOPPING:
                return new Failure(new \LogicException(
                    "Cannot stop server: currently STOPPING"
                ));
            case self::STARTING:
                return new Failure(new \LogicException(
                    "Cannot stop server: currently STARTING"
                ));
            default:
                return new Success;
        }
    }

    private function doStop(): Generator {
        assert($this->logDebug("stopping"));
        $this->state = self::STOPPING;

        foreach ($this->acceptWatcherIds as $watcherId) {
            $this->reactor->cancel($watcherId);
        }
        $this->boundServers = [];
        $this->acceptWatcherIds = [];
        foreach ($this->pendingTlsStreams as list(, $socket)) {
            $this->failCryptoNegotiation($socket);
        }

        $this->stopPromisor = new Deferred;
        if (empty($this->clients)) {
            $this->stopPromisor->succeed();
        } else {
            foreach ($this->clients as $client) {
                if (empty($client->requestCycles)) {
                    $this->close($client);
                }
            }
        }

        yield all([$this->stopPromisor->promise(), $this->notify()]);

        assert($this->logDebug("stopped"));
        $this->state = self::STOPPED;
        $this->stopPromisor = null;

        yield $this->notify();
    }

    private function importClient($socket, string $peerName, HttpDriver $http) {
        $client = new Client;
        $client->id = (int) $socket;
        $client->socket = $socket;
        $client->httpDriver = $http;
        $client->exporter = $this->exporter;
        $client->requestsRemaining = $this->options->maxRequests;

        $portStartPos = strrpos($peerName, ":");
        $client->clientAddr = substr($peerName, 0, $portStartPos);
        $client->clientPort = substr($peerName, $portStartPos + 1);

        $serverName = stream_socket_get_name($socket, false);
        $portStartPos = strrpos($serverName, ":");
        $client->serverAddr = substr($serverName, 0, $portStartPos);
        $client->serverPort = substr($serverName, $portStartPos + 1);

        $meta = stream_get_meta_data($socket);
        $client->cryptoInfo = $meta["crypto"] ?? [];
        $client->isEncrypted = (bool) $client->cryptoInfo;

        $client->requestParser = $http->parser($client);
        $client->requestParser->send("");
        $client->readWatcher = $this->reactor->onReadable($socket, $this->onReadable, $options = [
            "enable" => true,
            "cb_data" => $client,
        ]);
        $client->writeWatcher = $this->reactor->onWritable($socket, $this->onWritable, $options = [
            "enable" => false,
            "cb_data" => $client,
        ]);

        $this->renewKeepAliveTimeout($client);

        $this->clients[$client->id] = $client;
    }

    private function startWrite(Client $client, $final = false) {
        $this->onWritable($this->reactor, $client->writeWatcher, $client->socket, $client);

        if ($final) {
            $this->reactor->immediately(function() use ($client) {
                if ($client->requestParser) {
                    $client->requestParser->send("");
                }
            });

            if ($client->writeBuffer == "") {
                $this->onCompletedData($client);
            } else {
                $client->onWriteDrain = $this->onCompletedData;
            }
        }
    }

    private function onWritable(Reactor $reactor, string $watcherId, $socket, $client) {
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

    private function timeoutKeepAlives(int $now) {
        foreach ($this->keepAliveTimeouts as $id => $expiresAt) {
            if ($now > $expiresAt) {
                $this->close($this->clients[$id]);
            } else {
                break;
            }
        }
    }

    private function renewKeepAliveTimeout(Client $client) {
        $timeoutAt = $this->timeContext->currentTime + $this->options->keepAliveTimeout;
        // DO NOT remove the call to unset(); it looks superfluous but it's not.
        // Keep-alive timeout entries must be ordered by value. This means that
        // it's not enough to replace the existing map entry -- we have to remove
        // it completely and push it back onto the end of the array to maintain the
        // correct order.
        unset($this->keepAliveTimeouts[$client->id]);
        $this->keepAliveTimeouts[$client->id] = $timeoutAt;
    }

    private function clearKeepAliveTimeout(Client $client) {
        unset($this->keepAliveTimeouts[$client->id]);
    }

    private function onReadable(Reactor $reactor, string $watcherId, $socket, $client) {
        $data = @fread($socket, $this->options->ioGranularity);
        if ($data == "") {
            if (!is_resource($socket) || @feof($socket)) {
                $client->isDead = true;
                $this->close($client);
            }
            return;
        }

        // We only renew the keep-alive timeout if the client isn't waiting
        // for us to generate a response. (@TODO)
        $this->renewKeepAliveTimeout($client);

        $send = $client->requestParser->send($data);
        if ($send != "") {
            $client->writeBuffer .= $send;
            $this->onWritable($reactor, $client->writeWatcher, $client->socket, $client);
        }
    }

    private function onParse(array $parseStruct, $client) {
        list($eventType, $parseResult, $errorStruct) = $parseStruct;
        switch ($eventType) {
            case HttpDriver::RESULT:
                $this->onParsedMessageWithoutEntity($client, $parseResult);
                break;
            case HttpDriver::ENTITY_HEADERS:
                $this->onParsedEntityHeaders($client, $parseResult);
                break;
            case HttpDriver::ENTITY_PART:
                $this->onParsedEntityPart($client, $parseResult);
                break;
            case HttpDriver::ENTITY_RESULT:
                $this->onParsedMessageWithEntity($client, $parseResult);
                break;
            case HttpDriver::ERROR:
                $this->onParseError($client, $parseResult, $errorStruct);
                break;
            case HttpDriver::UPGRADE:
                // @TODO ensure that all of preface were consumed... (24 bytes for HTTP/2)
                $this->onParseUpgrade($client, $parseResult);
                break;
            default:
                assert(false, "Unexpected parser result code encountered");
        }
    }

    private function onParsedMessageWithoutEntity(Client $client, array $parseResult) {
        $ireq = $this->initializeRequest($client, $parseResult);
        $this->clearKeepAliveTimeout($client);

        // @TODO find better way to hook in!?
        if ($this->httpDriver[$parseResult["protocol"]] != $client->httpDriver) {
            $this->onParseUpgrade($client, $parseResult);
        }

        $this->respond($ireq);
    }

    private function onParsedEntityPart(Client $client, array $parseResult) {
        $id = $parseResult["id"];
        $client->bodyPromisors[$id]->update($parseResult["body"]);
    }

    private function onParsedEntityHeaders(Client $client, array $parseResult) {
        $ireq = $this->initializeRequest($client, $parseResult);
        $id = $parseResult["id"];
        $client->bodyPromisors[$id] = $bodyPromisor = new Deferred;
        $ireq->body = new StreamBody($bodyPromisor->promise());
        $this->clearKeepAliveTimeout($client);

        $this->respond($ireq);
    }

    private function onParsedMessageWithEntity(Client $client, array $parseResult) {
        $id = $parseResult["id"];
        $client->bodyPromisors[$id]->succeed();
        $client->bodyPromisors[$id] = null;
        // @TODO Update trailer headers if present

        // Don't respond() because we always start the response when headers arrive

        // @TODO find better way to hook in!?
        if ($this->httpDriver[$parseResult["protocol"]] != $client->httpDriver) {
            $this->onParseUpgrade($client, $parseResult);
        }
    }

    private function onParseError(Client $client, array $parseResult, string $error) {
        // @TODO how to handle parse error with entity body after request cycle already started?

        $this->clearKeepAliveTimeout($client);
        $ireq = $this->initializeRequest($client, $parseResult);
        $ireq->preAppResponder = function(Request $request, Response $response) use ($error) {
            $status = HTTP_STATUS["BAD_REQUEST"];
            $body = makeGenericBody($status, [
                "msg" => $error,
            ]);
            $response->setStatus($status);
            $response->setHeader("Connection", "close");
            $response->end($body);
        };

        $this->respond($ireq);
    }

    private function onParseUpgrade(Client $client, array $parseResult) {
        $initBuffer = $parseResult["unparsed"];
        $client->httpDriver = $this->httpDriver[$parseResult["protocol"]];
        $client->requestParser = $client->httpDriver->parser($client);
        $client->requestParser->send($initBuffer);
    }

    private function initializeRequest(Client $client, array $parseResult): InternalRequest {
        $trace = $parseResult["trace"];
        $protocol = empty($parseResult["protocol"]) ? "1.0" : $parseResult["protocol"];
        $method = empty($parseResult["method"]) ? "GET" : $parseResult["method"];
        if ($this->options->normalizeMethodCase) {
            $method = strtoupper($method);
        }
        $uri = empty($parseResult["uri"]) ? "/" : $parseResult["uri"];
        $headers = empty($parseResult["headers"]) ? [] : $parseResult["headers"];

        assert($this->logDebug(sprintf(
            "%s %s HTTP/%s @ %s:%s",
            $method,
            $uri,
            $protocol,
            $client->clientAddr,
            $client->clientPort
        )));

        $client->requestsRemaining--;

        $ireq = new InternalRequest;
        $ireq->client = $client;
        $ireq->isServerStopping = (bool) $this->stopPromisor;
        $ireq->time = $this->timeContext->currentTime;
        $ireq->httpDate = $this->timeContext->currentHttpDate;
        $ireq->locals = [];
        $ireq->remaining = $client->requestsRemaining;
        $ireq->isEncrypted = $client->isEncrypted;
        $ireq->cryptoInfo = $client->cryptoInfo;
        $ireq->trace = $trace;
        $ireq->protocol = $protocol;
        $ireq->method = $method;
        $ireq->headers = $headers;
        $ireq->body = $this->nullBody;
        $ireq->serverPort = $client->serverPort;
        $ireq->serverAddr = $client->serverAddr;
        $ireq->clientPort = $client->clientPort;
        $ireq->clientAddr = $client->clientAddr;

        if (empty($ireq->headers["cookie"])) {
            $ireq->cookies = [];
        } else { // @TODO delay initialization
            $ireq->cookies = array_merge(...array_map("\\Aerys\\parseCookie", $ireq->headers["cookie"]));
        }

        $ireq->uriRaw = $uri;
        if (stripos($uri, "http://") === 0 || stripos($uri, "https://") === 0) {
            extract(parse_url($uri, EXTR_PREFIX_ALL, "uri"));
            $ireq->uriHost = $uri_host;
            $ireq->uriPort = $uri_port;
            $ireq->uriPath = $uri_path;
            $ireq->uriQuery = $uri_query;
            $ireq->uri = isset($uri_query) ? "{$uri_path}?{$uri_query}" : $uri_path;
        } elseif ($qPos = strpos($uri, '?')) {
            $ireq->uriQuery = substr($uri, $qPos + 1);
            $ireq->uriPath = substr($uri, 0, $qPos);
            $ireq->uri = "{$ireq->uriPath}?{$ireq->uriQuery}";
        } else {
            $ireq->uri = $ireq->uriPath = $uri;
        }

        if (!isset($this->httpDriver[$protocol])) {
            $ireq->preAppResponder = [$this, "sendPreAppVersionNotSupportedResponse"];
        }

        if (!$vhost = $this->vhostContainer->selectHost($ireq)) {
            $vhost = $this->vhostContainer->getDefaultHost();
            $ireq->preAppResponder = [$this, "sendPreAppInvalidHostResponse"];
        }

        $ireq->vhost = $vhost;

        if ($client->httpDriver instanceof Http1Driver && !$client->isEncrypted) {
            $h2cUpgrade = $headers["upgrade"][0] ?? "";
            $h2cSettings = $headers["http2-settings"][0] ?? "";
            if ($h2cUpgrade && $h2cSettings && strcasecmp($h2cUpgrade, "h2c") === 0) {
                $this->onParseUpgrade($client, ["unparsed" => "", "protocol" => "2.0"]);
            }
        }

        // @TODO Handle 100 Continue responses
        // $expectsContinue = empty($headers["expect"]) ? false : stristr($headers["expect"], "100-continue");

        return $ireq;
    }

    private function respond(InternalRequest $ireq) {
        if ($ireq->preAppResponder) {
            $application = $ireq->preAppResponder;
        } elseif ($this->stopPromisor) {
            $application = [$this, "sendPreAppServiceUnavailableResponse"];
        } elseif (!in_array($ireq->method, $this->options->allowedMethods)) {
            $application = [$this, "sendPreAppMethodNotAllowedResponse"];
        } elseif ($ireq->method === "TRACE") {
            $application = [$this, "sendPreAppTraceResponse"];
        } elseif ($ireq->method === "OPTIONS" && $ireq->uriRaw === "*") {
            $application = [$this, "sendPreAppOptionsResponse"];
        } else {
            $application = $ireq->vhost->getApplication();
        }

        $this->tryApplication($ireq, $application);
    }

    private function sendPreAppVersionNotSupportedResponse(Request $request, Response $response) {
        $status = HTTP_STATUS["HTTP_VERSION_NOT_SUPPORTED"];
        $body = makeGenericBody($status);
        $response->setStatus($status);
        $response->setHeader("Connection", "close");
        $response->end($body);
    }

    private function sendPreAppServiceUnavailableResponse(Request $request, Response $response) {
        $status = HTTP_STATUS["SERVICE_UNAVAILABLE"];
        $body = makeGenericBody($status);
        $response->setStatus($status);
        $response->setHeader("Connection", "close");
        $response->end($body);
    }

    private function sendPreAppInvalidHostResponse(Request $request, Response $response) {
        $status = HTTP_STATUS["BAD_REQUEST"];
        $body = makeGenericBody($status);
        $response->setStatus($status);
        $response->setReason("Bad Request: Invalid Host");
        $response->setHeader("Connection", "close");
        $response->end($body);
    }

    private function sendPreAppMethodNotAllowedResponse(Request $request, Response $response) {
        $status = HTTP_STATUS["METHOD_NOT_ALLOWED"];
        $body = makeGenericBody($status);
        $response->setStatus($status);
        $response->setHeader("Connection", "close");
        $response->setHeader("Allow", implode(",", $this->options->allowedMethods));
        $response->end($body);
    }

    private function sendPreAppTraceResponse(Request $request, Response $response) {
        $response->setStatus(HTTP_STATUS["OK"]);
        $response->setHeader("Content-Type", "message/http");
        $response->end($ireq->trace);
    }

    private function sendPreAppOptionsResponse(Request $request, Response $response) {
        $response->setStatus(HTTP_STATUS["OK"]);
        $response->setHeader("Allow", implode(",", $this->options->allowedMethods));
        $response->end(null);
    }

    private function onCompletedData(Client $client) {
        if ($client->shouldClose) {
            $this->close($client);
        } else {
            // @TODO we need a flag to know if we're awaiting data for the
            //       currentRequestCycle before renewing this timeout.
            $this->renewKeepAliveTimeout($client);
        }
    }

    private function tryApplication(InternalRequest $ireq, callable $application) {
        try {
            $response = $this->initResponse($ireq);
            $request = new StandardRequest($ireq);

            $out = ($application)($request, $response);
            if ($out instanceof Generator) {
                $promise = resolve($out, $this->reactor);
                $promise->when($this->onCoroutineAppResolve, $ireq);
            } elseif ($response->state() & Response::STARTED) {
                $response->end();
            } else {
                $status = HTTP_STATUS["NOT_FOUND"];
                $body = makeGenericBody($status, [
                    "sub_heading" => "Requested: {$ireq->uri}",
                ]);
                $response->setStatus($status);
                $response->end($body);
            }
        } catch (ClientException $error) {
            // Do nothing -- responder actions aren't required to catch this
        } catch (\BaseException $error) {
            $this->onApplicationError($error, $ireq);
        }
    }

    private function onCoroutineAppResolve($error, $result, $ireq) {
        if (empty($error)) {
            if ($ireq->client->isExported || $ireq->client->isDead) {
                return;
            } elseif ($ireq->response->state() & Response::STARTED) {
                $ireq->response->end();
            } else {
                $status = HTTP_STATUS["NOT_FOUND"];
                $body = makeGenericBody($status, [
                    "sub_heading" => "Requested: {$ireq->uri}",
                ]);
                $ireq->response->setStatus($status);
                $ireq->response->end($body);
            }
        } elseif (!$error instanceof ClientException) {
            // Ignore uncaught ClientException -- applications aren't required to catch this
            $this->onApplicationError($error, $ireq);
        }
    }

    private function onApplicationError(\BaseException $error, InternalRequest $ireq) {
        $client = $ireq->client;
        $this->logger->error($error);

        if ($client->isDead || $client->isExported) {
            // Responder actions may catch the initial ClientException and continue
            // doing further work. If an error arises at this point we can end up
            // here and our only option is to log the error.
            return;
        }

        // If response output has already started we can't proceed any further.
        if (isset($ireq->response) && $ireq->response->state() & Response::STARTED) {
            $this->close($client);
            return;
        }

        if (!$error instanceof CodecException) {
            $this->sendErrorResponse($error, $ireq);
            return;
        }

        do {
            $ireq->badFilterKeys[] = $error->getCode();
            $this->initResponse($ireq);
            try {
                $this->sendErrorResponse($error, $ireq);
                return;
            } catch (CodecException $error) {
                // Keep trying until no broken filters remain ...
                $this->logger->error($error);
            }
        } while (1);
    }

    private function sendErrorResponse(\BaseException $error, InternalRequest $ireq) {
        $status = HTTP_STATUS["INTERNAL_SERVER_ERROR"];
        $msg = ($this->options->debug)
            ? $this->makeDebugMessage($error, $ireq)
            : "<p>Something went wrong ...</p>"
        ;
        $body = makeGenericBody($status, [
            "sub_heading" =>"Requested: {$ireq->uri}",
            "msg" => $msg,
        ]);
        $ireq->response->setStatus(HTTP_STATUS["INTERNAL_SERVER_ERROR"]);
        $ireq->response->setHeader("Connection", "close");
        $ireq->response->end($body);
    }

    private function makeDebugMessage(\BaseException $error, InternalRequest $ireq): string {
        $vars = [
            "isEncrypted"   => ($ireq->isEncrypted ? "true" : "false"),
            "serverAddr"    => $ireq->serverAddr,
            "serverPort"    => $ireq->serverPort,
            "clientAddr"    => $ireq->clientAddr,
            "clientPort"    => $ireq->clientPort,
            "method"        => $ireq->method,
            "protocol"      => $ireq->protocol,
            "uri"           => $ireq->uriRaw,
            "headers"       => $ireq->headers,
        ];

        $map = function($s) { return substr($s, 4); };
        $vars = implode("\n", array_map($map, array_slice(explode("\n", print_r($vars, true)), 2, -2)));

        $msg[] = "<pre>{$error}</pre>";
        $msg[] = "\n<hr/>\n";
        $msg[] = "<pre>{$vars}</pre>";
        $msg[] = "\n";

        return implode($msg);
    }

    private function close(Client $client) {
        $this->clear($client);
        if (!$client->isDead) {
            @fclose($client->socket);
            $client->isDead = true;
        }
        $this->clientCount--;
        assert($this->logDebug("close {$client->clientAddr}:{$client->clientPort}"));
    }

    private function clear(Client $client) {
        $client->requestParser = null;
        $client->onWriteDrain = null;
        $this->reactor->cancel($client->readWatcher);
        $this->reactor->cancel($client->writeWatcher);
        $this->clearKeepAliveTimeout($client);
        unset($this->clients[$client->id]);
        if ($this->stopPromisor && empty($this->clients)) {
            $this->stopPromisor->succeed();
        }
    }

    private function export(Client $client): \Closure {
        $client->isDead = true;
        $client->isExported = true;
        $this->clear($client);

        assert($this->logDebug("export {$client->clientAddr}:{$client->clientPort}"));

        return $this->decrementer;
    }

    private function initResponse(InternalRequest $ireq): Response {
        $filters = [
            "\\Aerys\\startResponseFilter",
            "\\Aerys\\genericResponseFilter",
        ];

        if ($userFilters = $ireq->vhost->getFilters()) {
            $filters = array_merge($filters, array_values($userFilters));
        }
        if ($this->options->deflateEnable) {
            $filters[] = "\\Aerys\\deflateResponseFilter";
        }
        if ($ireq->protocol === "1.1") {
            $filters[] = "\\Aerys\\chunkedResponseFilter";
        }
        if ($ireq->method === "HEAD") {
            $filters[] = "\\Aerys\\nullBodyResponseFilter";
        }
        if (isset($ireq->headers["upgrade"]) && $ireq->headers["upgrade"] == "h2c" && $ireq->protocol == "1.1") {
            $filters[] = $this->http2Filter;
        }
        if ($ireq->badFilterKeys) {
            $filters = array_diff_key($filters, array_flip($ireq->badFilterKeys));
        }

        $ireq->responseWriter = $ireq->client->httpDriver->writer($ireq);
        $filter = $this->responseCodec(responseFilter($filters, $ireq, $this->options), $ireq);
        $filter->current(); // initialize filter

        return $ireq->response = new StandardResponse($filter);
    }

    private function responseCodec(\Generator $filter, InternalRequest $ireq) {
        while ($filter->valid()) {
            $cur = $filter->send(yield);
            if ($cur !== null) {
                $ireq->responseWriter->send($cur);
            }
        }
        $cur = $filter->getReturn();
        if ($cur !== null) {
            $ireq->responseWriter->send($cur);
        }
        $ireq->responseWriter->send(null);
    }

    private function http2Filter(InternalRequest $ireq) {
        $ireq->responseWriter->send([
            ":status" => HTTP_STATUS["SWITCHING_PROTOCOLS"],
            ":reason" => "Switching Protocols",
            "Connection" => "Upgrade",
            "Upgrade" => "h2c",
        ]);
        $ireq->responseWriter->send(false); // flush before replacing

        $http = $this->httpDriver["2.0"];
        $ireq->client->httpDriver = $http;
        $ireq->responseWriter = $http->writer($ireq);

    }

    /**
     * We frequently have to pass callables to outside code (like amp).
     * Use this method to generate a "publicly callable" closure from
     * a private method without exposing that method in the public API.
     */
    private function makePrivateCallable(string $method): \Closure {
        return (new \ReflectionClass($this))->getMethod($method)->getClosure($this);
    }

    /**
     * This function MUST always return TRUE. It should only be invoked
     * inside an assert() block so that we can cancel its opcodes when
     * in production mode. This approach allows us to take full advantage
     * of debug mode log output without adding superfluous method call
     * overhead in production environments.
     */
    private function logDebug($message) {
        $this->logger->log(Logger::DEBUG, (string) $message);
        return true;
    }
}
