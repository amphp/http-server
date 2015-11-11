<?php

namespace Aerys;

use Amp\{ Struct, Promise, Success, Failure, Deferred };
use function Amp\{ resolve, timeout, any, all, makeGeneratorError };

class Server {
    use Struct;

    const STOPPED  = 0;
    const STARTING = 1;
    const STARTED  = 2;
    const STOPPING = 3;
    const STATES = [
        self::STOPPED => "STOPPED",
        self::STARTING => "STARTING",
        self::STARTED => "STARTED",
        self::STOPPING => "STOPPING",
    ];

    private $state = self::STOPPED;
    private $options;
    private $vhosts;
    private $logger;
    private $ticker;
    private $observers;
    private $decrementer;
    private $acceptWatcherIds = [];
    private $boundServers = [];
    private $pendingTlsStreams = [];
    private $clients = [];
    private $clientCount = 0;
    private $keepAliveTimeouts = [];
    private $nullBody;
    private $stopPromisor;
    private $httpDriver;

    // private callables that we pass to external code //
    private $exporter;
    private $onAcceptable;
    private $negotiateCrypto;
    private $verifyCrypto;
    private $onReadable;
    private $onWritable;
    private $onCoroutineAppResolve;
    private $onResponseDataDone;

    public function __construct(Options $options, VhostContainer $vhosts, Logger $logger, Ticker $ticker, array $httpDrivers) {
        $this->options = $options;
        $this->vhosts = $vhosts;
        $this->logger = $logger;
        $this->ticker = $ticker;
        $this->observers = new \SplObjectStorage;
        $this->observers->attach($ticker);
        $this->decrementer = function() {
            if ($this->clientCount) {
                $this->clientCount--;
            }
        };
        $this->ticker->use($this->makePrivateCallable("timeoutKeepAlives"));
        $this->nullBody = new NullBody;

        // private callables that we pass to external code //
        $this->exporter = $this->makePrivateCallable("export");
        $this->onAcceptable = $this->makePrivateCallable("onAcceptable");
        $this->negotiateCrypto = $this->makePrivateCallable("negotiateCrypto");
        $this->verifyCrypto = $this->makePrivateCallable("verifyCrypto");
        $this->onReadable = $this->makePrivateCallable("onReadable");
        $this->onWritable = $this->makePrivateCallable("onWritable");
        $this->onCoroutineAppResolve = $this->makePrivateCallable("onCoroutineAppResolve");
        $this->onResponseDataDone = $this->makePrivateCallable("onResponseDataDone");

        foreach ($httpDrivers as $driver) {
            $emitter = $this->makePrivateCallable("onParseEmit");
            $writer = $this->makePrivateCallable("writeResponse");
            $http = $driver($emitter, $writer);
            \assert($http instanceof HttpDriver);
            foreach ($http->versions() as $version) {
                $this->httpDriver[$version] = $http;
            }
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
     * Assign a server option value
     *
     * @param string $option The option to retrieve
     * @param mixed $newValue
     * @throws \DomainException on unknown option
     * @return void
     */
    public function setOption(string $option, $newValue) {
        \assert($this->state < self::STARTED);
        $this->options->{$option} = $newValue;
    }

    /**
     * Attach an observer
     *
     * @param ServerObserver $observer
     * @return void
     */
    public function attach(ServerObserver $observer) {
        $this->observers->attach($observer);
    }

    /**
     * Detach an Observer
     *
     * @param ServerObserver $observer
     * @return void
     */
    public function detach(ServerObserver $observer) {
        $this->observers->detach($observer);
    }

    /**
     * Notify observers of a server state change
     *
     * Resolves to an indexed any() promise combinator array.
     *
     * @return \Amp\Promise
     */
    private function notify(): Promise {
        $promises = [];
        foreach ($this->observers as $observer) {
            $promises[] = $observer->update($this);
        }

        return any($promises)->when(function($error, $result) {
            // $error is always empty because an any() combinator promise never fails.
            // Instead we check the error array at index zero in the two-item amy() $result
            // and log as needed.
            list($observerErrors) = $result;
            foreach ($observerErrors as $error) {
                $this->logger->error($error);
            }
        });
    }

    /**
     * Start the server
     *
     * @return \Amp\Promise
     */
    public function start(): Promise {
        try {
            if ($this->state == self::STOPPED) {
                if ($this->vhosts->count() === 0) {
                    return new Failure(new \LogicException(
                        "Cannot start: no virtual hosts registered in composed VhostContainer"
                    ));
                }
                return resolve($this->doStart());
            } else {
                return new Failure(new \LogicException(
                    "Cannot start server: already ".self::STATES[$this->state]
                ));
            }
        } catch (\Throwable $uncaught) {
            return new Failure($uncaught);
        }
    }

    private function doStart(): \Generator {
        assert($this->logDebug("starting"));

        $addrCtxMap = $this->generateBindableAddressContextMap();
        foreach ($addrCtxMap as $address => $context) {
            $this->boundServers[$address] = $this->bind($address, $context);
        }

        $this->state = self::STARTING;
        $notifyResult = yield $this->notify();
        if ($hadErrors = (bool)$notifyResult[0]) {
            yield from $this->doStop();
            throw new \RuntimeException(
                "Server::STARTING observer initialization failure"
            );
        }

        /* enable direct access for performance, but Options now shouldn't be changed as Server has been STARTED */
        try {
            if (@\assert(false)) {
                $options = new class extends Options {
                    use \Amp\Struct;

                    private $_initialized = false;

                    public function __get(string $prop) {
                        throw new \DomainException(
                            $this->generateStructPropertyError($prop)
                        );
                    }

                    public function __set(string $prop, $val) {
                        if ($this->_initialized) {
                            throw new \RuntimeException("Cannot add options after server has STARTED.");
                        }
                        $this->$prop = $val;
                    }
                };
                foreach ((new \ReflectionObject($this->options))->getProperties() as $property) {
                    $name = $property->getName();
                    $options->$name = $this->options->$name;
                }
                $this->options = $options;
            }
        } catch (\AssertionError $e) { }

        // lock options
        $this->options->_initialized = true;

        $this->dropPrivileges();

        $this->state = self::STARTED;
        assert($this->logDebug("started"));

        foreach ($this->boundServers as $serverName => $server) {
            $this->acceptWatcherIds[$serverName] = \Amp\onReadable($server, $this->onAcceptable);
            $this->logger->info("Listening on {$serverName}");
        }

        return $this->notify()->when(function($e, $notifyResult) {
            if ($hadErrors = $e || $notifyResult[0]) {
                resolve($this->doStop())->when(function($e) {
                    throw new \RuntimeException(
                        "Server::STARTED observer initialization failure", 0, $e
                    );
                });
            }
        });
    }

    private function generateBindableAddressContextMap() {
        $addrCtxMap = [];
        $addresses = $this->vhosts->getBindableAddresses();
        $tlsBindings = $this->vhosts->getTlsBindingsByAddress();
        $backlogSize = $this->options->socketBacklogSize;
        $shouldReusePort = !$this->options->debug;

        foreach ($addresses as $address) {
            $context = stream_context_create(["socket" => [
                "backlog"      => $backlogSize,
                "so_reuseport" => $shouldReusePort,
                "ipv6_v6only"  => true,
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

    private function onAcceptable(string $watcherId, $server) {
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
            $watcherId = \Amp\onReadable($client, $this->verifyCrypto, $options = [
                "cb_data" => $peerName,
            ]);
            $this->pendingTlsStreams[$clientId] = [$watcherId, $client];
        } else {
            $this->importClient($client, $peerName, $this->httpDriver["1.1"]);
        }
    }

    private function verifyCrypto(string $watcherId, $socket, string $peerName) {
        $raw = stream_socket_recvfrom($socket, 11, \STREAM_PEEK);
        if (\strlen($raw) < 11) {
            if (@feof($socket)) {
                \assert($this->logDebug("crypto message not received: {$peerName} disconnected"));
                $this->failCryptoNegotiation($socket);
            }
            return;
        }

        $data = unpack("ctype/nversion/nlength/Nembed/ntopversion", $raw);
        // do not assert anything here, user might accidentally send a request via http for example
        if ($data["version"] < 0x303 && $data["version"] >= 0x301) { // minimum version: TLS, but older than TLS 1.2
            if ($data["topversion"] >= 0x303) {
                // force at least TLS 1.2 if available! (This is important as some browsers reject HTTP/2 connections with TLS 1.0 or 1.1)
                stream_context_set_option($socket, "ssl", "crypto_method", STREAM_CRYPTO_METHOD_TLSv1_2_SERVER);
            }
        }

        \Amp\cancel($watcherId);
        $watcherId = \Amp\onReadable($socket, $this->negotiateCrypto, $options = [
            "cb_data" => $peerName,
        ]);
        $this->pendingTlsStreams[(int) $socket][0] = $watcherId;
        $this->negotiateCrypto($watcherId, $socket, $peerName);
    }

    private function negotiateCrypto(string $watcherId, $socket, string $peerName) {
        if ($handshake = @stream_socket_enable_crypto($socket, true)) {
            $socketId = (int) $socket;
            \Amp\cancel($watcherId);
            unset($this->pendingTlsStreams[$socketId]);
            $meta = stream_get_meta_data($socket)["crypto"];
            $isH2 = (isset($meta["alpn_protocol"]) && $meta["alpn_protocol"] === "h2");
            \assert($this->logDebug(sprintf("crypto negotiated %s%s", ($isH2 ? "(h2) " : ""), $peerName)));
            // Dispatch via HTTP 1 driver for now, it knows how to handle PRI * requests, maybe we can improve that later...
            $this->importClient($socket, $peerName, $this->httpDriver["1.1"]);
        } elseif ($handshake === false) {
            \assert($this->logDebug("crypto handshake error {$peerName}"));
            $this->failCryptoNegotiation($socket);
        }
    }

    private function failCryptoNegotiation($socket) {
        $this->clientCount--;
        $socketId = (int) $socket;
        list($watcherId) = $this->pendingTlsStreams[$socketId];
        \Amp\cancel($watcherId);
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
                $stopPromise = resolve($this->doStop());
                return timeout($stopPromise, $this->options->shutdownTimeout);
            case self::STOPPED:
                return new Success;
            default:
                return new Failure(new \LogicException(
                    "Cannot stop server: currently ".self::STATES[$this->state]
                ));
        }
    }

    private function doStop(): \Generator {
        assert($this->logDebug("stopping"));
        $this->state = self::STOPPING;

        foreach ($this->acceptWatcherIds as $watcherId) {
            \Amp\cancel($watcherId);
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
        $client->options = $this->options;
        $client->exporter = $this->exporter;
        $client->remainingKeepAlives = $this->options->maxKeepAliveRequests ?: null;

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

        $client->readWatcher = \Amp\onReadable($socket, $this->onReadable, $options = [
            "enable" => true,
            "cb_data" => $client,
        ]);
        $client->writeWatcher = \Amp\onWritable($socket, $this->onWritable, $options = [
            "enable" => false,
            "cb_data" => $client,
        ]);

        $this->clients[$client->id] = $client;

        $client->requestParser = $http->parser($client);
        $client->requestParser->valid();

        $this->renewKeepAliveTimeout($client);
    }

    private function writeResponse(Client $client, $final = false) {
        $this->onWritable($client->writeWatcher, $client->socket, $client);
        if (empty($final)) {
            return;
        }

        $client->parserEmitLock = false;
        if ($client->writeBuffer == "") {
            $this->onResponseDataDone($client);
        } else {
            $client->onWriteDrain = $this->onResponseDataDone;
        }
    }

    private function onResponseDataDone(Client $client) {
        if ($client->shouldClose) {
            $this->close($client);
        } else {
            $this->renewKeepAliveTimeout($client);
        }
    }

    private function onWritable(string $watcherId, $socket, $client) {
        $bytesWritten = @fwrite($socket, $client->writeBuffer);
        if ($bytesWritten === false) {
            if (!is_resource($socket) || @feof($socket)) {
                $client->isDead = true;
                $this->close($client);
            }
        } elseif ($bytesWritten === strlen($client->writeBuffer)) {
            $client->writeBuffer = "";
            \Amp\disable($watcherId);
            if ($client->onWriteDrain) {
                ($client->onWriteDrain)($client);
            }
        } else {
            $client->writeBuffer = substr($client->writeBuffer, $bytesWritten);
            \Amp\enable($watcherId);
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
        $timeoutAt = $this->ticker->currentTime + $this->options->keepAliveTimeout;
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

    private function onReadable(string $watcherId, $socket, $client) {
        $data = @fread($socket, $this->options->ioGranularity);
        if ($data == "") {
            if (!is_resource($socket) || @feof($socket)) {
                $client->isDead = true;
                $this->close($client);
            }
            return;
        }

        // We should only renew the keep-alive timeout if the client isn't waiting
        // for us to generate a response. (@TODO)
        $this->renewKeepAliveTimeout($client);

        $send = $client->requestParser->send($data);
        if ($client->parserEmitLock) {
            $client->parserEmitLock = false;
        }

        if ($send != "") {
            $client->writeBuffer .= $send;
            $this->onWritable($client->writeWatcher, $client->socket, $client);
        }
    }

    private function onParseEmit(array $parseStruct, $client) {
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
                // assumes all of preface were consumed... (24 bytes for HTTP/2)
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
        $ireq->body = new Body($bodyPromisor->promise());
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
            "%s %s HTTP/%s @ %s:%s%s",
            $method,
            $uri,
            $protocol,
            $client->clientAddr,
            $client->clientPort,
            empty($parseResult["server_push"]) ? "" : " (server-push via {$parseResult["server_push"]})"
        )));

        if (isset($client->remainingKeepAlives)) {
            $client->remainingKeepAlives--;
        }

        $ireq = new InternalRequest;
        $ireq->client = $client;
        $ireq->isServerStopping = (bool) $this->stopPromisor;
        $ireq->time = $this->ticker->currentTime;
        $ireq->httpDate = $this->ticker->currentHttpDate;
        $ireq->locals = [];
        $ireq->trace = $trace;
        $ireq->protocol = $protocol;
        $ireq->method = $method;
        $ireq->headers = $headers;
        $ireq->body = $this->nullBody;
        $ireq->streamId = $parseResult["id"];

        if (empty($ireq->headers["cookie"])) {
            $ireq->cookies = [];
        } else { // @TODO delay initialization
            $ireq->cookies = array_merge(...array_map("\\Aerys\\parseCookie", $ireq->headers["cookie"]));
        }

        $ireq->uriRaw = $uri;
        if (stripos($uri, "http://") === 0 || stripos($uri, "https://") === 0) {
            extract(parse_url($uri), EXTR_PREFIX_ALL, "uri");
            $ireq->uriHost = $uri_host;
            $ireq->uriPort = $uri_port ?? 80;
            $ireq->uriPath = $uri_path;
            $ireq->uriQuery = $uri_query ?? "";
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

        if (!$vhost = $this->vhosts->selectHost($ireq)) {
            $vhost = $this->vhosts->getDefaultHost();
            $ireq->preAppResponder = [$this, "sendPreAppInvalidHostResponse"];
        }

        $ireq->vhost = $vhost;

        if ($client->httpDriver instanceof Http1Driver && !$client->isEncrypted) {
            $h2cUpgrade = $headers["upgrade"][0] ?? "";
            if ($h2cUpgrade && strcasecmp($h2cUpgrade, "h2c") === 0) {
                if (isset($headers["http2-settings"][0]) && false !== $h2cSettings = base64_decode($headers["http2-settings"])) {
                    $h2cSettingsFrame = substr(pack("N", \strlen($h2cSettings)), 1, 3) . Http2Driver::SETTINGS . Http2Driver::NOFLAG . "\0\0\0\0$h2cSettings";
                    $this->onParseUpgrade($client, ["unparsed" => $h2cSettingsFrame, "protocol" => "2.0"]);
                } else {
                    // handle case where no http2-settings header is specified
                }
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

    private function tryApplication(InternalRequest $ireq, callable $application) {
        try {
            $response = $this->initializeResponse($ireq);
            $request = new StandardRequest($ireq);

            $out = ($application)($request, $response);
            if ($out instanceof \Generator) {
                $promise = resolve($out);
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
        } catch (\Throwable $error) {
            $this->onApplicationError($error, $ireq);
        }
    }

    private function initializeResponse(InternalRequest $ireq): Response {
        $ireq->responseWriter = $ireq->client->httpDriver->writer($ireq);
        $filters = $ireq->client->httpDriver->filters($ireq);
        if ($ireq->badFilterKeys) {
            $filters = array_diff_key($filters, array_flip($ireq->badFilterKeys));
        }
        $filter = responseFilter($filters, $ireq);
        $filter->current(); // initialize filters
        $codec = $this->responseCodec($filter, $ireq);

        return $ireq->response = new StandardResponse($codec);
    }

    private function responseCodec(\Generator $filter, InternalRequest $ireq): \Generator {
        while (($yield = yield) !== null) {
            $cur = $filter->send($yield);
            if ($yield === false) {
                if ($cur !== null) {
                    $ireq->responseWriter->send($cur);
                    if (\is_array($cur)) { // in case of headers, to flush a maybe started body too, we need to send false twice
                        $cur = $filter->send(false);
                        if ($cur !== null) {
                            $ireq->responseWriter->send($cur);
                        }
                    }
                }
                $ireq->responseWriter->send(false);
            } elseif ($cur !== null) {
                $ireq->responseWriter->send($cur);
            }
        }

        $cur = $filter->send(null);
        if (\is_array($cur)) {
            $ireq->responseWriter->send($cur);
            $filter->send(null);
        }
        \assert($filter->valid() === false);

        $cur = $filter->getReturn();
        if ($cur !== null) {
            $ireq->responseWriter->send($cur);
        }
        $ireq->responseWriter->send(null);
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

    private function onApplicationError(\Throwable $error, InternalRequest $ireq) {
        $this->logger->error($error);

        if ($ireq->client->isDead || $ireq->client->isExported) {
            // Responder actions may catch an initial ClientException and continue
            // doing further work. If an error arises at this point our only option
            // is to log the error (which we just did above).
            return;
        } elseif (isset($ireq->response) && $ireq->response->state() & Response::STARTED) {
            $this->close($ireq->client);
        } elseif (empty($ireq->filterErrorFlag)) {
            $this->tryErrorResponse($error, $ireq);
        } else {
            $this->tryFilterErrorResponse($error, $ireq);
        }
    }

    /**
     * When an uncaught exception is thrown by a filter we enable the $ireq->filterErrorFlag
     * and add the offending filter's key to $ireq->badFilterKeys. Each time we initialize
     * a response the bad filters are removed from the chain in an effort to invoke all possible
     * filters. To handle the scenario where multiple filters error we need to continue looping
     * until $ireq->filterErrorFlag no longer reports as true.
     */
    private function tryFilterErrorResponse(\Throwable $error, InternalRequest $ireq) {
        while ($ireq->filterErrorFlag) {
            try {
                $ireq->filterErrorFlag = false;
                $this->initializeResponse($ireq);
                $this->tryErrorResponse($error, $ireq);
            } catch (ClientException $error) {
                return;
            } catch (\Throwable $error) {
                $this->logger->error($error);
                $this->close($ireq->client);
            }
        }
    }

    private function tryErrorResponse(\Throwable $error, InternalRequest $ireq) {
        try {
            $status = HTTP_STATUS["INTERNAL_SERVER_ERROR"];
            $msg = ($this->options->debug) ? "<pre>{$error}</pre>" : "<p>Something went wrong ...</p>";
            $body = makeGenericBody($status, [
                "sub_heading" =>"Requested: {$ireq->uri}",
                "msg" => $msg,
            ]);
            $ireq->response->setStatus(HTTP_STATUS["INTERNAL_SERVER_ERROR"]);
            $ireq->response->setHeader("Connection", "close");
            $ireq->response->end($body);
        } catch (ClientException $error) {
            return;
        } catch (\Throwable $error) {
            if ($ireq->filterErrorFlag) {
                $this->tryFilterErrorResponse($error, $ireq);
            } else {
                $this->logger->error($error);
                $this->close($ireq->client);
            }
        }
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
        \Amp\cancel($client->readWatcher);
        \Amp\cancel($client->writeWatcher);
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

    private function dropPrivileges() {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return;
        }

        $user = $this->options->user;
        if (!extension_loaded("posix")) {
            if ($user !== null) {
                throw new \RuntimeException("Posix extension must be enabled to switch to user '{$user}'!");
            } else {
                $this->logger->warning("Posix extension not enabled, be sure not to run your server as root!");
            }
        } elseif (posix_geteuid() === 0) {
            if ($user === null) {
                $this->logger->warning("Running as privileged user is discouraged! Use the 'user' option to switch to another user after startup!");
                return;
            }

            $info = posix_getpwnam($user);
            if (!$info) {
                throw new \RuntimeException("Switching to user '{$user}' failed, because it doesn't exist!");
            }

            $success = posix_seteuid($info["uid"]);
            if (!$success) {
                throw new \RuntimeException("Switching to user '{$user}' failed, probably because of missing privileges.'");
            }
        }
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

    public function __debugInfo() {
        return [
            "state" => $this->state,
            "vhosts" => $this->vhosts,
            "ticker" => $this->ticker,
            "observers" => $this->observers,
            "acceptWatcherIds" => $this->acceptWatcherIds,
            "boundServers" => $this->boundServers,
            "pendingTlsStreams" => $this->pendingTlsStreams,
            "clients" => $this->clients,
            "keepAliveTimeouts" => $this->keepAliveTimeouts,
            "stopPromise" => $this->stopPromisor ? $this->stopPromisor->promise() : null,
            "httpDriver" => $this->httpDriver,
        ];
    }
}
