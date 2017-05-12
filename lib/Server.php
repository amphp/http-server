<?php

namespace Aerys;

use Amp\{ Struct, Promise, Success, Failure, Deferred };
use function Amp\{ resolve, timeout, any, all, makeGeneratorError };
use Psr\Log\LoggerInterface as PsrLogger;

class Server implements Monitor {
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
    private $acceptWatcherIds = [];
    private $boundServers = [];
    private $pendingTlsStreams = [];
    private $clients = [];
    private $clientCount = 0;
    private $clientsPerIP = [];
    private $keepAliveTimeouts = [];
    private $nullBody;
    private $stopPromisor;

    // private callables that we pass to external code //
    private $exporter;
    private $onAcceptable;
    private $negotiateCrypto;
    private $onReadable;
    private $onWritable;
    private $onCoroutineAppResolve;
    private $onResponseDataDone;

    public function __construct(Options $options, VhostContainer $vhosts, PsrLogger $logger, Ticker $ticker) {
        $this->options = $options;
        $this->vhosts = $vhosts;
        $this->logger = $logger;
        $this->ticker = $ticker;
        $this->observers = new \SplObjectStorage;
        $this->observers->attach($ticker);
        $this->ticker->use($this->makePrivateCallable("timeoutKeepAlives"));
        $this->nullBody = new NullBody;

        // private callables that we pass to external code //
        $this->exporter = $this->makePrivateCallable("export");
        $this->onAcceptable = $this->makePrivateCallable("onAcceptable");
        $this->negotiateCrypto = $this->makePrivateCallable("negotiateCrypto");
        $this->onReadable = $this->makePrivateCallable("onReadable");
        $this->onWritable = $this->makePrivateCallable("onWritable");
        $this->onCoroutineAppResolve = $this->makePrivateCallable("onCoroutineAppResolve");
        $this->onResponseDataDone = $this->makePrivateCallable("onResponseDataDone");
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
            // Instead we check the error array at index zero in the two-item any() $result
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

        $emitter = $this->makePrivateCallable("onParseEmit");
        $writer = $this->makePrivateCallable("writeResponse");
        $this->vhosts->setupHttpDrivers($emitter, $writer);

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

        /* Options now shouldn't be changed as Server has been STARTED - lock them */
        $this->options->__initialized = true;

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
        if (!$socket = stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context)) {
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
        if (!$client = @\stream_socket_accept($server, $timeout = 0, $peerName)) {
            return;
        }

        $portStartPos = strrpos($peerName, ":");
        $ip = substr($peerName, 0, $portStartPos);
        $port = substr($peerName, $portStartPos + 1);
        $net = @\inet_pton($ip);
        if (isset($net[4])) {
            $perIP = &$this->clientsPerIP[substr($net, 0, 7 /* /56 block */)];
        } else {
            $perIP = &$this->clientsPerIP[$net];
        }

        if (($this->clientCount++ === $this->options->maxConnections) | ($perIP++ === $this->options->connectionsPerIP)) {
            assert($this->logDebug("client denied: too many existing connections"));
            $this->clientCount--;
            $perIP--;
            @fclose($client);
            return;
        }

        \assert($this->logDebug("accept {$peerName}"));

        \stream_set_blocking($client, false);
        $contextOptions = \stream_context_get_options($client);
        if (isset($contextOptions["ssl"])) {
            $clientId = (int) $client;
            $watcherId = \Amp\onReadable($client, $this->negotiateCrypto, $options = [
                "cb_data" => [$ip, $port],
            ]);
            $this->pendingTlsStreams[$clientId] = [$watcherId, $client];
        } else {
            $this->importClient($client, $ip, $port);
        }
    }

    private function negotiateCrypto(string $watcherId, $socket, $peer) {
        list($ip, $port) = $peer;
        if ($handshake = @\stream_socket_enable_crypto($socket, true)) {
            $socketId = (int)$socket;
            \Amp\cancel($watcherId);
            unset($this->pendingTlsStreams[$socketId]);
            assert((function () use ($socket, $ip, $port) {
                $meta = stream_get_meta_data($socket)["crypto"];
                $isH2 = (isset($meta["alpn_protocol"]) && $meta["alpn_protocol"] === "h2");
                return $this->logDebug(sprintf("crypto negotiated %s%s:%d", ($isH2 ? "(h2) " : ""), $ip, $port));
            })());
            // Dispatch via HTTP 1 driver; it knows how to handle PRI * requests - for now it is easier to dispatch only via content (ignore alpn)...
            $this->importClient($socket, $ip, $port);
        } elseif ($handshake === false) {
            assert($this->logDebug("crypto handshake error $ip:$port"));
            $this->failCryptoNegotiation($socket, $ip);
        }
    }

    private function failCryptoNegotiation($socket, $ip) {
        $this->clientCount--;
        $net = @\inet_pton($ip);
        if (isset($net[4])) {
            $net = substr($net, 0, 7 /* /56 block */);
        }
        $this->clientsPerIP[$net]--;

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
            $this->failCryptoNegotiation($socket, key($this->clientsPerIP) /* doesn't matter after stop */);
        }

        $this->stopPromisor = new Deferred;
        if (empty($this->clients)) {
            $this->stopPromisor->succeed();
        } else {
            foreach ($this->clients as $client) {
                if (empty($client->requestCycles)) {
                    $this->close($client);
                } else {
                    $client->remainingKeepAlives = PHP_INT_MIN;
                }
            }
        }

        yield all([$this->stopPromisor->promise(), $this->notify()]);

        assert($this->logDebug("stopped"));
        $this->state = self::STOPPED;
        $this->stopPromisor = null;

        yield $this->notify();
    }

    private function importClient($socket, $ip, $port) {
        $client = new Client;
        $client->id = (int) $socket;
        $client->socket = $socket;
        $client->options = $this->options;
        $client->exporter = $this->exporter;
        $client->remainingKeepAlives = $this->options->maxKeepAliveRequests ?: PHP_INT_MAX;

        $client->clientAddr = $ip;
        $client->clientPort = $port;

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

        $client->httpDriver = $this->vhosts->selectHttpDriver($client->serverAddr, $client->serverPort);
        $client->requestParser = $client->httpDriver->parser($client);
        $client->requestParser->valid();

        $this->renewKeepAliveTimeout($client);
    }

    private function writeResponse(Client $client, $final = false) {
        $this->onWritable($client->writeWatcher, $client->socket, $client);

        if (empty($final)) {
            return;
        }

        if ($client->writeBuffer == "") {
            $this->onResponseDataDone($client);
        } else {
            $client->onWriteDrain = $this->onResponseDataDone;
        }
    }

    private function onResponseDataDone(Client $client) {
        if ($client->shouldClose || (--$client->pendingResponses == 0 && $client->isDead == Client::CLOSED_RD)) {
            $this->close($client);
        } elseif (!($client->isDead & Client::CLOSED_RD)) {
            $this->renewKeepAliveTimeout($client);
        }
    }

    private function onWritable(string $watcherId, $socket, Client $client) {
        $bytesWritten = @\fwrite($socket, $client->writeBuffer);
        if ($bytesWritten === false || ($bytesWritten === 0 && (!\is_resource($socket) || @\feof($socket)))) {
            if ($client->isDead == Client::CLOSED_RD) {
                $this->close($client);
            } else {
                $client->isDead = Client::CLOSED_WR;
                $client->writeWatcher = null;
                \Amp\cancel($watcherId);
            }
        } else {
            $client->bufferSize -= $bytesWritten;
            if ($bytesWritten === \strlen($client->writeBuffer)) {
                $client->writeBuffer = "";
                \Amp\disable($watcherId);
                if ($client->onWriteDrain) {
                    ($client->onWriteDrain)($client);
                }
            } else {
                $client->writeBuffer = \substr($client->writeBuffer, $bytesWritten);
                \Amp\enable($watcherId);
            }
            if ($client->bufferPromisor && $client->bufferSize <= $client->options->softStreamCap) {
                $promisor = $client->bufferPromisor;
                $client->bufferPromisor = null;
                $promisor->succeed();
            }
        }
    }

    private function timeoutKeepAlives(int $now) {
        $timeouts = [];
        foreach ($this->keepAliveTimeouts as $id => $expiresAt) {
            if ($now > $expiresAt) {
                $timeouts[] = $this->clients[$id];
            } else {
                break;
            }
        }
        foreach ($timeouts as $client) {
            // do not close in case some longer response is taking longer, but do in case bodyPromisors aren't fulfilled
            if ($client->pendingResponses > \count($client->bodyPromisors)) {
                $this->clearKeepAliveTimeout($client);
            } else {
                // timeouts are only active while Client is doing nothing (not sending nor receving) and no pending writes, hence we can just fully close here
                $this->close($client);
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

    private function onReadable(string $watcherId, $socket, Client $client) {
        $data = @\fread($socket, $this->options->ioGranularity);
        if ($data == "") {
            if (!\is_resource($socket) || @\feof($socket)) {
                if ($client->isDead == Client::CLOSED_WR || $client->pendingResponses == 0) {
                    $this->close($client);
                } else {
                    $client->isDead = Client::CLOSED_RD;
                    \Amp\cancel($watcherId);
                    $client->readWatcher = null;
                    if ($client->bodyPromisors) {
                        $ex = new ClientException;
                        foreach ($client->bodyPromisors as $key => $promisor) {
                            $promisor->fail($ex);
                            $client->bodyPromisors[$key] = new Deferred;
                        }
                    }
                }
            }
            return;
        }

        $this->renewKeepAliveTimeout($client);
        $client->requestParser->send($data);
    }

    private function onParseEmit(Client $client, $eventType, $parseResult, $errorStruct = null) {
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
            case HttpDriver::SIZE_WARNING:
                $this->onEntitySizeWarning($client, $parseResult);
                break;
            case HttpDriver::ERROR:
                $this->onParseError($client, $parseResult, $errorStruct);
                break;
            default:
                assert(false, "Unexpected parser result code encountered");
        }
    }

    private function onParsedMessageWithoutEntity(Client $client, array $parseResult) {
        $ireq = $this->initializeRequest($client, $parseResult);

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

        $this->respond($ireq);
    }

    private function onParsedMessageWithEntity(Client $client, array $parseResult) {
        $id = $parseResult["id"];
        $promisor = $client->bodyPromisors[$id];
        unset($client->bodyPromisors[$id]);
        $promisor->succeed();
        // @TODO Update trailer headers if present

        // Don't respond() because we always start the response when headers arrive
    }

    private function onEntitySizeWarning(Client $client, array $parseResult) {
        $id = $parseResult["id"];
        $promisor = $client->bodyPromisors[$id];
        $client->bodyPromisors[$id] = new Deferred;
        $promisor->fail(new ClientSizeException);
    }

    private function onParseError(Client $client, array $parseResult, string $error) {
        $this->clearKeepAliveTimeout($client);

        if ($client->bodyPromisors) {
            $client->writeBuffer .= "\n\n$error";
            $client->shouldClose = true;
            $this->writeResponse($client, true);
            return;
        }

        $ireq = $this->initializeRequest($client, $parseResult);

        $client->pendingResponses++;
        $this->tryApplication($ireq, static function(Request $request, Response $response) use ($parseResult, $error) {
            if ($error === HttpDriver::BAD_VERSION) {
                $status = HTTP_STATUS["HTTP_VERSION_NOT_SUPPORTED"];
                $error = "Unsupported version {$parseResult['protocol']}";
            } else {
                $status = HTTP_STATUS["BAD_REQUEST"];
            }
            $body = makeGenericBody($status, [
                "msg" => $error,
            ]);
            $response->setStatus($status);
            $response->setHeader("Connection", "close");
            $response->end($body);
        }, []);
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
            $ireq->cookies = array_merge(...array_map('\Aerys\parseCookie', $ireq->headers["cookie"]));
        }

        $ireq->uriRaw = $uri;
        if (stripos($uri, "http://") === 0 || stripos($uri, "https://") === 0) {
            $uri = parse_url($uri);
            $ireq->uriHost = $uri["host"];
            $ireq->uriPort = isset($uri["port"]) ? (int) $uri["port"] : $client->serverPort;
            $ireq->uriPath = \rawurldecode($uri["path"]);
            $ireq->uriQuery = $uri["query"] ?? "";
            $ireq->uri = isset($uri["query"]) ? "{$ireq->uriPath}?{$uri['query']}" : $ireq->uriPath;
        } else {
            if ($qPos = strpos($uri, '?')) {
                $ireq->uriQuery = substr($uri, $qPos + 1);
                $ireq->uriPath = \rawurldecode(substr($uri, 0, $qPos));
                $ireq->uri = "{$ireq->uriPath}?{$ireq->uriQuery}";
            } else {
                $ireq->uri = $ireq->uriPath = \rawurldecode($uri);
                $ireq->uriQuery = "";
            }
            $host = $ireq->headers["host"][0] ?? "";
            if (($colon = strrpos($host, ":")) !== false) {
                $ireq->uriHost = substr($host, 0, $colon);
                $ireq->uriPort = (int) substr($host, $colon + 1);
            } else {
                $ireq->uriHost = $host;
                $ireq->uriPort = $client->serverPort;
            }
        }

        return $ireq;
    }

    private function setTrace(InternalRequest $ireq) {
        if (\is_string($ireq->trace)) {
            $ireq->locals['aerys.trace'] = $ireq->trace;
        } else {
            $trace = "{$ireq->method} {$ireq->uri} {$ireq->protocol}\r\n";
            foreach ($ireq->trace as list($header, $value)) {
                $trace .= "$header: $value\r\n";
            }
            $ireq->locals['aerys.trace'] = $trace;
        }
    }

    private function respond(InternalRequest $ireq) {
        $ireq->client->pendingResponses++;

        if ($this->stopPromisor) {
            $this->tryApplication($ireq, [$this, "sendPreAppServiceUnavailableResponse"], []);
        } elseif (!in_array($ireq->method, $this->options->allowedMethods)) {
            $this->tryApplication($ireq, [$this, "sendPreAppMethodNotAllowedResponse"], []);
        } elseif (!$vhost = $this->vhosts->selectHost($ireq)) {
            $this->tryApplication($ireq, [$this, "sendPreAppInvalidHostResponse"], []);
        } elseif ($ireq->method === "TRACE") {
            $this->setTrace($ireq);
            $this->tryApplication($ireq, [$this, "sendPreAppTraceResponse"], []);
        } elseif ($ireq->method === "OPTIONS" && $ireq->uriRaw === "*") {
            $this->tryApplication($ireq, [$this, "sendPreAppOptionsResponse"], []);
        } else {
            $this->tryApplication($ireq, $vhost->getApplication(), $vhost->getFilters());
        }
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
        $response->end($request->getLocalVar('aerys.trace'));
    }

    private function sendPreAppOptionsResponse(Request $request, Response $response) {
        $response->setStatus(HTTP_STATUS["OK"]);
        $response->setHeader("Allow", implode(",", $this->options->allowedMethods));
        $response->end(null);
    }

    private function tryApplication(InternalRequest $ireq, callable $application, array $filters) {
        $response = $this->initializeResponse($ireq, $filters);
        $request = new StandardRequest($ireq);

        try {
            $out = ($application)($request, $response);
            if ($out instanceof \Generator) {
                $promise = resolve($out);
                $promise->when($this->onCoroutineAppResolve, [$ireq, $response, $filters]);
            } elseif ($out instanceof Promise) {
                $out->when($this->onCoroutineAppResolve, [$ireq, $response, $filters]);
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
        } catch (ClientSizeException $error) {
            if (!($response->state() & Response::STARTED)) {
                $status = HTTP_STATUS["REQUEST_ENTITY_TOO_LARGE"];
                $body = makeGenericBody($status, [
                    "sub_heading" => "Requested: {$ireq->uri}",
                ]);
                $response->setStatus($status);
                $response->end($body);
            }
        } catch (ClientException $error) {
            // Do nothing -- responder actions aren't required to catch this
        } catch (\Throwable $error) {
            $this->onApplicationError($error, $ireq, $response, $filters);
        }
    }

    private function initializeResponse(InternalRequest $ireq, array $filters): Response {
        $ireq->responseWriter = $ireq->client->httpDriver->writer($ireq);
        $filters = $ireq->client->httpDriver->filters($ireq, $filters);
        if ($ireq->badFilterKeys) {
            $filters = array_diff_key($filters, array_flip($ireq->badFilterKeys));
        }
        $filter = responseFilter($filters, $ireq);
        $filter->current(); // initialize filters
        $codec = responseCodec($filter, $ireq);

        return new StandardResponse($codec, $ireq->client);
    }

    private function onCoroutineAppResolve($error, $result, $info) {
        list($ireq, $response, $filters) = $info;
        if (empty($error)) {
            if ($ireq->client->isExported || ($ireq->client->isDead & Client::CLOSED_WR)) {
                return;
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
        } elseif (!$error instanceof ClientException) {
            // Ignore uncaught ClientException -- applications aren't required to catch this
            $this->onApplicationError($error, $ireq, $response, $filters);
        }
    }

    private function onApplicationError(\Throwable $error, InternalRequest $ireq, Response $response, array $filters) {
        $this->logger->error($error);

        if (($ireq->client->isDead & Client::CLOSED_WR) || $ireq->client->isExported) {
            // Responder actions may catch an initial ClientException and continue
            // doing further work. If an error arises at this point our only option
            // is to log the error (which we just did above).
            return;
        } elseif ($response->state() & Response::STARTED) {
            $this->close($ireq->client);
        } elseif (empty($ireq->filterErrorFlag)) {
            $this->tryErrorResponse($error, $ireq, $response, $filters);
        } else {
            $this->tryFilterErrorResponse($error, $ireq, $filters);
        }
    }

    /**
     * When an uncaught exception is thrown by a filter we enable the $ireq->filterErrorFlag
     * and add the offending filter's key to $ireq->badFilterKeys. Each time we initialize
     * a response the bad filters are removed from the chain in an effort to invoke all possible
     * filters. To handle the scenario where multiple filters error we need to continue looping
     * until $ireq->filterErrorFlag no longer reports as true.
     */
    private function tryFilterErrorResponse(\Throwable $error, InternalRequest $ireq, array $filters) {
        while ($ireq->filterErrorFlag) {
            try {
                $ireq->filterErrorFlag = false;
                $response = $this->initializeResponse($ireq, $filters);
                $this->tryErrorResponse($error, $ireq, $response, $filters);
            } catch (ClientException $error) {
                return;
            } catch (\Throwable $error) {
                $this->logger->error($error);
                $this->close($ireq->client);
            }
        }
    }

    private function tryErrorResponse(\Throwable $error, InternalRequest $ireq, Response $response, array $filters) {
        try {
            $status = HTTP_STATUS["INTERNAL_SERVER_ERROR"];
            $msg = ($this->options->debug) ? "<pre>" . htmlspecialchars($error) . "</pre>" : "<p>Something went wrong ...</p>";
            $body = makeGenericBody($status, [
                "sub_heading" =>"Requested: {$ireq->uri}",
                "msg" => $msg,
            ]);
            $response->setStatus(HTTP_STATUS["INTERNAL_SERVER_ERROR"]);
            $response->setHeader("Connection", "close");
            $response->end($body);
        } catch (ClientException $error) {
            return;
        } catch (\Throwable $error) {
            if ($ireq->filterErrorFlag) {
                $this->tryFilterErrorResponse($error, $ireq, $filters);
            } else {
                $this->logger->error($error);
                $this->close($ireq->client);
            }
        }
    }

    private function close(Client $client) {
        $this->clear($client);
        assert($client->isDead != Client::CLOSED_RDWR);
        @fclose($client->socket);
        $client->isDead = Client::CLOSED_RDWR;

        $this->clientCount--;
        $net = @\inet_pton($client->clientAddr);
        if (isset($net[4])) {
            $net = substr($net, 0, 7 /* /56 block */);
        }
        $this->clientsPerIP[$net]--;
        assert($this->logDebug("close {$client->clientAddr}:{$client->clientPort}"));
        if ($client->bodyPromisors) {
            $ex = new ClientException;
            foreach ($client->bodyPromisors as $key => $promisor) {
                $promisor->fail($ex);
                $client->bodyPromisors[$key] = new Deferred;
            }
        }
        if ($client->bufferPromisor) {
            $ex = $ex ?? new ClientException;
            $client->bufferPromisor->fail($ex);
        }

    }

    private function clear(Client $client) {
        $client->requestParser = null; // break cyclic reference
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
        $client->isDead = Client::CLOSED_RDWR;
        $client->isExported = true;
        $this->clear($client);

        assert($this->logDebug("export {$client->clientAddr}:{$client->clientPort}"));

        $net = @\inet_pton($client->clientAddr);
        if (isset($net[4])) {
            $net = substr($net, 0, 7 /* /56 block */);
        }
        $clientCount = &$this->clientCount;
        $clientsPerIP = &$this->clientsPerIP[$net];
        $closer = static function() use (&$clientCount, &$clientsPerIP) {
            $clientCount--;
            $clientsPerIP--;
        };
        assert($closer = (function() use ($client, &$clientCount, &$clientsPerIP) {
            $logger = $this->logger;
            $message = "close {$client->clientAddr}:{$client->clientPort}";
            return static function() use (&$clientCount, &$clientsPerIP, $logger, $message) {
                $clientCount--;
                $clientsPerIP--;
                assert($clientCount >= 0);
                assert($clientsPerIP >= 0);
                $logger->log(Logger::DEBUG, $message);
            };
        })());
        return $closer;
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
        ];
    }

    public function monitor(): array {
        return [
            "state" => $this->state,
            "bindings" => $this->vhosts->getBindableAddresses(),
            "clients" => count($this->clients),
            "IPs" => count($this->clientsPerIP),
            "pendingInputs" => count($this->keepAliveTimeouts),
            "hosts" => $this->vhosts->monitor(),
        ];
    }
}
