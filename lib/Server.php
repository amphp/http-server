<?php

namespace Aerys;

use Amp\CallableMaker;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Failure;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;

class Server {
    use CallableMaker;

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

    const KNOWN_METHODS = ["GET", "HEAD", "POST", "PUT", "PATCH", "DELETE", "OPTIONS", "TRACE"];

    /** @var int */
    private $state = self::STOPPED;

    /** @var \Aerys\Options */
    private $immutableOptions;

    /** @var \Aerys\Internal\Options */
    private $options;

    /** @var \Aerys\Internal\Host */
    private $host;

    /** @var \Aerys\Internal\HttpDriver */
    private $httpDriver;

    /** @var \Aerys\Responder */
    private $responder;

    /** @var \Aerys\ErrorHandler */
    private $errorHandler;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var \Aerys\Internal\Ticker */
    private $ticker;

    /** @var \SplObjectStorage */
    private $observers;

    /** @var string[] */
    private $acceptWatcherIds = [];

    /** @var resource[] Server sockets. */
    private $boundServers = [];

    /** @var resource[] */
    private $pendingTlsStreams = [];

    /** @var \Aerys\Internal\Client[] */
    private $clients = [];

    /** @var int */
    private $clientCount = 0;

    /** @var int[] */
    private $clientsPerIP = [];

    /** @var int[] */
    private $connectionTimeouts = [];

    /** @var \Aerys\NullBody */
    private $nullBody;

    /** @var \Amp\Deferred|null */
    private $stopDeferred;

    // private callables that we pass to external code //
    private $onAcceptable;
    private $onUnixSocketAcceptable;
    private $negotiateCrypto;
    private $onReadable;
    private $onWritable;
    private $onResponseDataDone;

    /**
     * @param \Aerys\Responder $responder
     * @param \Aerys\Options|null $options Null creates an Options object with all default options.
     * @param \Psr\Log\LoggerInterface|null $logger Null automatically uses an instance of \Aerys\ConsoleLogger.
     *
     * @throws \Error If $responder is not a callable or instance of Responder.
     */
    public function __construct(Responder $responder, Options $options = null, PsrLogger $logger = null) {
        $this->responder = $responder;

        $this->host = new Internal\Host;

        $this->immutableOptions = $options ?? new Options;
        $this->options = $this->immutableOptions->export();
        $this->logger = $logger ?? new ConsoleLogger(new Console);

        $this->ticker = new Internal\Ticker($this->logger);
        $this->ticker->use($this->callableFromInstanceMethod("timeoutKeepAlives"));

        $this->observers = new \SplObjectStorage;
        $this->observers->attach($this->ticker);

        $this->nullBody = new NullBody;

        if ($this->responder instanceof ServerObserver) {
            $this->observers->attach($this->responder);
        }

        $this->errorHandler = new DefaultErrorHandler;

        // private callables that we pass to external code //
        $this->onAcceptable = $this->callableFromInstanceMethod("onAcceptable");
        $this->onUnixSocketAcceptable = $this->callableFromInstanceMethod("onUnixSocketAcceptable");
        $this->negotiateCrypto = $this->callableFromInstanceMethod("negotiateCrypto");
        $this->onReadable = $this->callableFromInstanceMethod("onReadable");
        $this->onWritable = $this->callableFromInstanceMethod("onWritable");
        $this->onResponseDataDone = $this->callableFromInstanceMethod("onResponseDataDone");

        $this->setupHttpDriver(new Internal\Http1Driver);
    }

    /**
     * Assign the IP or unix domain socket and port on which to listen. This method may be called
     * multiple times to listen on multiple interfaces.
     *
     * The address may be any valid IPv4 or IPv6 address or unix domain socket path. The "0.0.0.0"
     * indicates "all IPv4 interfaces" and is appropriate for most users. Use "::" to indicate "all
     * IPv6 interfaces". Use a "*" wildcard character to indicate "all IPv4 *and* IPv6 interfaces".
     *
     * Note that "::" may also listen on some systems on IPv4 interfaces. PHP did not expose the
     * IPV6_V6ONLY constant before PHP 7.0.1.
     *
     * Any valid port number [1-65535] may be used. Port numbers lower than 256 are reserved for
     * well-known services (like HTTP on port 80) and port numbers less than 1024 require root
     * access on UNIX-like systems. The default port for encrypted sockets (https) is 443. If you
     * plan to use encryption with this host you'll generally want to use port 443.
     *
     * @param string $address The IPv4 or IPv6 interface or unix domain socket path to listen to
     * @param int $port The port number on which to listen (0 for unix domain sockets)
     *
     * @throws \Error If the server has started.
     */
    public function expose(string $address, int $port = 0) {
        if ($this->state) {
            throw new \Error("Cannot add connection interfaces after the server has started");
        }

        $this->host->expose($address, $port);
    }

    /**
     * Define TLS encryption settings for this server.
     *
     * @param string|\Amp\Socket\Certificate|\Amp\Socket\ServerTlsContext $certificate A string path pointing to your
     *     SSL/TLS certificate, a Certificate object, or a ServerTlsContext object
     *
     * @throws \Error If the server has started.
     */
    public function encrypt($certificate) {
        if ($this->state) {
            throw new \Error("Cannot add a certificate after the server has started");
        }

        $this->host->encrypt($certificate);
    }

    /**
     * Set the error handler instance to be used for generating error responses.
     *
     * @param \Aerys\ErrorHandler $errorHandler
     *
     * @throws \Error If the server has started.
     */
    public function setErrorHandler(ErrorHandler $errorHandler) {
        if ($this->state) {
            throw new \Error("Cannot set the error handler after the server has started");
        }

        $this->errorHandler = $errorHandler;
    }

    /**
     * Retrieve the current server state.
     *
     * @return int
     */
    public function getState(): int {
        return $this->state;
    }

    /**
     * Retrieve the server options object.
     *
     * @return \Aerys\Options
     */
    public function getOptions(): Options {
        return $this->immutableOptions;
    }

    /**
     * Attach an observer.
     *
     * @param ServerObserver $observer
     *
     * @throws \Error If the server has started.
     */
    public function attach(ServerObserver $observer) {
        if ($this->state) {
            throw new \Error("Cannot attach observers after the server has started");
        }

        $this->observers->attach($observer);
    }

    /**
     * @param callable $callback
     */
    public function tick(callable $callback) {
        $this->ticker->use($callback);
    }

    /**
     * Start the server.
     *
     * @param callable(array) $bindSockets is passed the $address => $context map
     *
     * @return \Amp\Promise
     */
    public function start(callable $bindSockets = null): Promise {
        try {
            if ($this->state === self::STOPPED) {
                return new Coroutine($this->doStart($bindSockets ?? \Amp\coroutine(function ($addrCtxMap, $socketBinder) {
                    $serverSockets = [];
                    foreach ($addrCtxMap as $address => $context) {
                        $serverSockets[$address] = $socketBinder($address, $context);
                    }
                    return $serverSockets;
                })));
            }

            return new Failure(new \Error(
                "Cannot start server: already ".self::STATES[$this->state]
            ));
        } catch (\Throwable $uncaught) {
            return new Failure($uncaught);
        }
    }

    private function doStart(callable $bindSockets): \Generator {
        assert($this->logDebug("Starting"));

        $socketBinder = function ($address, $context) {
            if (!strncmp($address, "unix://", 7)) {
                @unlink(substr($address, 7));
            }

            if (!$socket = stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create($context))) {
                throw new \RuntimeException(sprintf("Failed binding socket on %s: [Err# %s] %s", $address, $errno, $errstr));
            }

            return $socket;
        };

        $this->boundServers = yield $bindSockets($this->generateBindableAddressContextMap(), $socketBinder);

        $this->state = self::STARTING;
        try {
            $promises = [];
            foreach ($this->observers as $observer) {
                $promises[] = $observer->onStart($this, $this->logger, $this->errorHandler);
            }
            yield $promises;
        } catch (\Throwable $exception) {
            yield from $this->doStop();
            throw new \RuntimeException("onStart observer initialization failure", 0, $exception);
        }

        $this->dropPrivileges();

        $this->state = self::STARTED;
        assert($this->logDebug("Started"));

        foreach ($this->boundServers as $serverName => $server) {
            $onAcceptable = $this->onAcceptable;
            if (!strncmp($serverName, "unix://", 7)) {
                $onAcceptable = $this->onUnixSocketAcceptable;
            }
            $this->acceptWatcherIds[$serverName] = Loop::onReadable($server, $onAcceptable);
            $this->logger->info("Listening on {$serverName}");
        }
    }

    /**
     * @param \Aerys\Internal\HttpDriver $httpDriver
     *
     * @throws \Error If the server has started.
     */
    private function setupHttpDriver(Internal\HttpDriver $httpDriver) {
        if ($this->state) {
            throw new \Error("Cannot setup HTTP driver after the server has started");
        }

        $this->httpDriver = $httpDriver;
        $this->httpDriver->setup(
            $this,
            $this->callableFromInstanceMethod("onParsedMessage"),
            $this->callableFromInstanceMethod("onParseError"),
            $this->callableFromInstanceMethod("writeResponse")
        );
    }

    private function generateBindableAddressContextMap(): array {
        $addrCtxMap = [];
        $addresses = $this->host->getBindableAddresses();
        $tlsContext = $this->host->getTlsContext();
        $backlogSize = $this->options->socketBacklogSize;
        $shouldReusePort = !$this->options->debug;

        foreach ($addresses as $address) {
            $context = ["socket" => [
                "backlog"      => $backlogSize,
                "so_reuseport" => $shouldReusePort,
                "so_reuseaddr" => stripos(PHP_OS, "WIN") === 0, // SO_REUSEADDR has SO_REUSEPORT semantics on Windows
                "ipv6_v6only"  => true,
            ]];
            if ($tlsContext) {
                $context["ssl"] = $tlsContext;
            }
            $addrCtxMap[$address] = $context;
        }

        return $addrCtxMap;
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

        \assert($this->logDebug("Accept {$peerName} on " . stream_socket_get_name($client, false) . " #" . (int) $client));

        \stream_set_blocking($client, false);
        $contextOptions = \stream_context_get_options($client);
        if (isset($contextOptions["ssl"])) {
            $clientId = (int) $client;
            $watcherId = Loop::onReadable($client, $this->negotiateCrypto, [$ip, $port]);
            $this->pendingTlsStreams[$clientId] = [$watcherId, $client];
        } else {
            $this->importClient($client, $ip, $port);
        }
    }

    private function onUnixSocketAcceptable(string $watcherId, $server) {
        if (!$client = @\stream_socket_accept($server, $timeout = 0)) {
            return;
        }

        \assert($this->logDebug("Accept connection on " . stream_socket_get_name($client, false) . " #" . (int) $client));

        \stream_set_blocking($client, false);
        $this->importClient($client, "", 0);
    }

    private function negotiateCrypto(string $watcherId, $socket, $peer) {
        list($ip, $port) = $peer;
        if ($handshake = @\stream_socket_enable_crypto($socket, true)) {
            $socketId = (int) $socket;
            Loop::cancel($watcherId);
            unset($this->pendingTlsStreams[$socketId]);
            assert((function () use ($socket, $ip, $port) {
                $meta = stream_get_meta_data($socket)["crypto"];
                $isH2 = (isset($meta["alpn_protocol"]) && $meta["alpn_protocol"] === "h2");
                return $this->logDebug(sprintf("Crypto negotiated %s%s:%d", ($isH2 ? "(h2) " : ""), $ip, $port));
            })());
            // Dispatch via HTTP 1 driver; it knows how to handle PRI * requests and will check alpn_protocol value.
            $this->importClient($socket, $ip, $port);
        } elseif ($handshake === false) {
            assert($this->logDebug("Crypto handshake error $ip:$port"));
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
        Loop::cancel($watcherId);
        unset($this->pendingTlsStreams[$socketId]);
        @\stream_socket_shutdown($socket, \STREAM_SHUT_RDWR); // ensures a TCP FIN frame is sent even if other processes (forks) have inherited the fd
        @\fclose($socket);
    }

    /**
     * Stop the server.
     *
     * @return Promise
     */
    public function stop(): Promise {
        switch ($this->state) {
            case self::STARTED:
                $stopPromise = new Coroutine($this->doStop());
                return Promise\timeout($stopPromise, $this->options->shutdownTimeout);
            case self::STOPPED:
                return new Success;
            default:
                return new Failure(new \Error(
                    "Cannot stop server: currently ".self::STATES[$this->state]
                ));
        }
    }

    private function doStop(): \Generator {
        assert($this->logDebug("Stopping"));
        $this->state = self::STOPPING;

        foreach ($this->acceptWatcherIds as $watcherId) {
            Loop::cancel($watcherId);
        }
        $this->boundServers = [];
        $this->acceptWatcherIds = [];
        foreach ($this->pendingTlsStreams as list(, $socket)) {
            $this->failCryptoNegotiation($socket, key($this->clientsPerIP) /* doesn't matter after stop */);
        }

        try {
            $promises = [];
            foreach ($this->observers as $observer) {
                $promises[] = $observer->onStop($this);
            }
            yield $promises;
        } catch (\Throwable $exception) {
            // Exception will be rethrown below once all clients are disconnected.
        }

        $this->stopDeferred = new Deferred;
        if (empty($this->clients)) {
            $this->stopDeferred->resolve();
        } else {
            foreach ($this->clients as $client) {
                if (empty($client->pendingResponses)) {
                    $this->close($client);
                } else {
                    $client->remainingRequests = 0;
                }
            }
        }

        yield $this->stopDeferred->promise();

        assert($this->logDebug("Stopped"));
        $this->state = self::STOPPED;
        $this->stopDeferred = null;

        if (isset($exception)) {
            throw new \RuntimeException("onStop observer failure", 0, $exception);
        }
    }

    private function importClient($socket, $ip, $port) {
        $client = new Internal\Client;
        $client->id = (int) $socket;
        $client->socket = $socket;
        $client->options = $this->options;
        $client->remainingRequests = $this->options->maxRequestsPerConnection;

        $client->clientAddr = $ip;
        $client->clientPort = $port;

        $serverName = stream_socket_get_name($socket, false);
        if ($portStartPos = strrpos($serverName, ":")) {
            $client->serverAddr = substr($serverName, 0, $portStartPos);
            $client->serverPort = (int) substr($serverName, $portStartPos + 1);
        } else {
            $client->serverAddr = $serverName;
            $client->serverPort = 0;
        }

        $meta = stream_get_meta_data($socket);
        $client->cryptoInfo = $meta["crypto"] ?? [];
        $client->isEncrypted = (bool) $client->cryptoInfo;

        $client->readWatcher = Loop::onReadable($socket, $this->onReadable, $client);
        $client->writeWatcher = Loop::onWritable($socket, $this->onWritable, $client);
        Loop::disable($client->writeWatcher);

        $this->clients[$client->id] = $client;

        $client->httpDriver = $this->httpDriver;
        $client->requestParser = $client->httpDriver->parser($client);
        $client->requestParser->valid();

        $this->renewConnectionTimeout($client);
    }

    private function writeResponse(Internal\Client $client, string $data, bool $final = false) {
        $client->writeBuffer .= $data;

        if (!$final && \strlen($client->writeBuffer) < $client->options->outputBufferSize) {
            return;
        }

        $this->onWritable($client->writeWatcher, $client->socket, $client);

        $length = \strlen($client->writeBuffer);

        if ($length > $client->options->softStreamCap) {
            $client->bufferDeferred = new Deferred;
        }

        if (!$final) {
            return;
        }

        if ($length === 0) {
            $this->onResponseDataDone($client);
        } else {
            $client->onWriteDrain = $this->onResponseDataDone;
        }
    }

    private function onResponseDataDone(Internal\Client $client) {
        if ($client->shouldClose || (--$client->pendingResponses === 0 && $client->isDead === Internal\Client::CLOSED_RD)) {
            $this->close($client);
        } elseif (!($client->isDead & Internal\Client::CLOSED_RD)) {
            $this->renewConnectionTimeout($client);
        }
    }

    private function onWritable(string $watcherId, $socket, Internal\Client $client) {
        $bytesWritten = @\fwrite($socket, $client->writeBuffer);
        if ($bytesWritten === false || ($bytesWritten === 0 && (!\is_resource($socket) || @\feof($socket)))) {
            if ($client->isDead === Internal\Client::CLOSED_RD) {
                $this->close($client);
            } else {
                $client->isDead = Internal\Client::CLOSED_WR;
                Loop::cancel($watcherId);
            }
        } else {
            if ($bytesWritten === \strlen($client->writeBuffer)) {
                $client->writeBuffer = "";
                Loop::disable($watcherId);
                if ($client->onWriteDrain) {
                    ($client->onWriteDrain)($client);
                }
            } else {
                $client->writeBuffer = \substr($client->writeBuffer, $bytesWritten);
                Loop::enable($watcherId);
            }
            if ($client->bufferDeferred && \strlen($client->writeBuffer) <= $client->options->softStreamCap) {
                $deferred = $client->bufferDeferred;
                $client->bufferDeferred = null;
                $deferred->resolve();
            }
        }
    }

    private function timeoutKeepAlives(int $now) {
        $timeouts = [];
        foreach ($this->connectionTimeouts as $id => $expiresAt) {
            if ($now > $expiresAt) {
                $timeouts[] = $this->clients[$id];
            } else {
                break;
            }
        }

        foreach ($timeouts as $client) {
            // Do not close in case some longer response is taking more time to complete.
            if ($client->pendingResponses > \count($client->bodyEmitters)) {
                $this->clearConnectionTimeout($client); // Will be re-enabled once response is written.
            } else {
                // Timeouts are only active while Client is doing nothing (not sending nor receiving) and no pending
                // writes, hence we can just fully close here
                $this->close($client);
            }
        }
    }

    private function renewConnectionTimeout(Internal\Client $client) {
        $timeoutAt = $this->ticker->getCurrentTime() + $this->options->connectionTimeout;
        // DO NOT remove the call to unset(); it looks superfluous but it's not.
        // Keep-alive timeout entries must be ordered by value. This means that
        // it's not enough to replace the existing map entry -- we have to remove
        // it completely and push it back onto the end of the array to maintain the
        // correct order.
        unset($this->connectionTimeouts[$client->id]);
        $this->connectionTimeouts[$client->id] = $timeoutAt;
    }

    private function clearConnectionTimeout(Internal\Client $client) {
        unset($this->connectionTimeouts[$client->id]);
    }

    private function onReadable(string $watcherId, $socket, Internal\Client $client) {
        $data = @\stream_get_contents($socket, $this->options->ioGranularity);
        if ($data !== false && $data !== "") {
            $this->renewConnectionTimeout($client);
            $client->requestParser->send($data);
            return;
        }

        if (!\is_resource($socket) || @\feof($socket)) {
            if ($client->isDead === Internal\Client::CLOSED_WR || $client->pendingResponses <= \count($client->bodyEmitters)) {
                $this->close($client);
                return;
            }

            $client->isDead = Internal\Client::CLOSED_RD;
            Loop::cancel($watcherId);
        }
    }

    private function onParsedMessage(Internal\Client $client, Request $request) {
        assert($this->logDebug(sprintf(
            "%s %s HTTP/%s @ %s:%s",
            $request->getMethod(),
            $request->getUri(),
            $request->getProtocolVersion(),
            $client->clientAddr,
            $client->clientPort
        )));

        $client->remainingRequests--;
        $client->pendingResponses++;

        Promise\rethrow(new Coroutine($this->respond($client, $request)));
    }

    private function onParseError(Internal\Client $client, int $status, string $message) {
        Loop::disable($client->readWatcher);

        $this->logger->notice("Client parse error on {$client->clientAddr}:{$client->clientPort}: {$message}");

        $client->shouldClose = true;
        $client->pendingResponses++;

        Promise\rethrow(new Coroutine($this->sendErrorResponse($client, $status, $message)));
    }

    /**
     * Respond to a parse error when parsing a client message.
     *
     * @param \Aerys\Internal\Client $client
     * @param int $status
     * @param string $reason
     *
     * @return \Generator
     */
    private function sendErrorResponse(Internal\Client $client, int $status, string $reason): \Generator {
        try {
            $response = yield $this->errorHandler->handle($status, $reason);
        } catch (\Throwable $exception) {
            $response = yield $this->makeExceptionResponse($exception);
        }

        yield from $this->sendResponse($client, $response);
    }

    /**
     * Respond to a parsed request from the client.
     *
     * @param \Aerys\Request $request
     *
     * @return \Generator
     */
    private function respond(Internal\Client $client, Request $request): \Generator {
        try {
            $method = $request->getMethod();

            if ($this->stopDeferred) {
                $response = yield from $this->makeServiceUnavailableResponse();
            } elseif (!\in_array($method, $this->options->allowedMethods, true)) {
                if (!\in_array($method, self::KNOWN_METHODS, true)) {
                    $response = yield from $this->makeNotImplementedResponse();
                } else {
                    $response = yield from $this->makeMethodNotAllowedResponse();
                }
            } elseif ($method === "OPTIONS" && $request->getTarget() === "*") {
                $response = $this->makeOptionsResponse();
            } else {
                $response = yield $this->responder->respond($request);

                if (!$response instanceof Response) {
                    throw new \Error("At least one request handler must return an instance of " . Response::class);
                }
            }
        } catch (ClientException $exception) {
            return; // Handled in code that generated the ClientException, response already sent.
        } catch (\Throwable $error) {
            $this->logger->error($error);
            $response = yield $this->makeExceptionResponse($error, $request);
        }

        yield from $this->sendResponse($client, $response, $request);
    }

    private function makeServiceUnavailableResponse(): \Generator {
        $status = HttpStatus::SERVICE_UNAVAILABLE;
        /** @var \Aerys\Response $response */
        $response = yield $this->errorHandler->handle($status, HttpStatus::getReason($status));
        $response->setHeader("Connection", "close");
        return $response;
    }

    private function makeMethodNotAllowedResponse(): \Generator {
        $status = HttpStatus::METHOD_NOT_ALLOWED;
        /** @var \Aerys\Response $response */
        $response = yield $this->errorHandler->handle($status, HttpStatus::getReason($status));
        $response->setHeader("Connection", "close");
        $response->setHeader("Allow", \implode(", ", $this->options->allowedMethods));
        return $response;
    }

    private function makeNotImplementedResponse(): \Generator {
        $status = HttpStatus::NOT_IMPLEMENTED;
        /** @var \Aerys\Response $response */
        $response = yield $this->errorHandler->handle($status, HttpStatus::getReason($status));
        $response->setHeader("Connection", "close");
        $response->setHeader("Allow", \implode(", ", $this->options->allowedMethods));
        return $response;
    }

    private function makeOptionsResponse(): Response {
        return new Response\EmptyResponse(["Allow" => implode(", ", $this->options->allowedMethods)]);
    }

    /**
     * Send the response to the client.
     *
     * @param \Aerys\Internal\Client $client
     * @param \Aerys\Response $response
     * @param \Aerys\Request|null $request
     *
     * @return \Generator
     */
    private function sendResponse(Internal\Client $client, Response $response, Request $request = null): \Generator {
        $responseWriter = $client->httpDriver->writer($client, $response, $request);

        $body = $response->getBody();

        try {
            do {
                $chunk = yield $body->read();

                if ($client->isDead & Internal\Client::CLOSED_WR) {
                    $responseWriter->send(null);
                    return;
                }

                $responseWriter->send($chunk); // Sends null when stream closes.
            } while ($chunk !== null);
        } catch (ClientException $exception) {
            return;
        } catch (\Throwable $exception) {
            // Reading response body failed, abort writing the response to the client.
            $this->logger->error($exception);
            $responseWriter->send(null);
            return;
        }

        try {
            if ($response->isDetached()) {
                $responseWriter->send(null);
                $this->export($client, $response);
            }
        } catch (\Throwable $exception) {
            $this->logger->error($exception);
        }
    }

    /**
     * @param \Throwable $error
     * @param \Aerys\Request $request
     *
     * @return \Amp\Promise<\Aerys\Response>
     */
    private function makeExceptionResponse(\Throwable $exception, Request $request = null): Promise {
        $status = HttpStatus::INTERNAL_SERVER_ERROR;

        // Return an HTML page with the exception in debug mode.
        if ($request !== null && $this->options->debug) {
            $html = \str_replace(
                ["{uri}", "{class}", "{message}", "{file}", "{line}", "{trace}"],
                \array_map("htmlspecialchars", [
                    $request->getUri(),
                    \get_class($exception),
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine(),
                    $exception->getTraceAsString()
                ]),
                INTERNAL_SERVER_ERROR_HTML
            );

            return new Success(new Response\HtmlResponse($html, [], $status));
        }

        try {
            // Return a response defined by the error handler in production mode.
            return $this->errorHandler->handle($status, HttpStatus::getReason($status), $request);
        } catch (\Throwable $exception) {
            // If the error handler throws, fallback to returning the default HTML error page.
            $this->logger->error($exception);

            $html = \str_replace(
                ["{code}", "{reason}"],
                \array_map("htmlspecialchars", [$status, HttpStatus::getReason($status)]),
                DEFAULT_ERROR_HTML
            );

            return new Success(new Response\HtmlResponse($html, [], $status));
        }
    }

    private function close(Internal\Client $client) {
        $this->clear($client);
        assert($client->isDead !== Internal\Client::CLOSED_RDWR);
        // ensures a TCP FIN frame is sent even if other processes (forks) have inherited the fd
        @\stream_socket_shutdown($client->socket, \STREAM_SHUT_RDWR);
        @fclose($client->socket);
        $client->isDead = Internal\Client::CLOSED_RDWR;

        $this->clientCount--;
        if ($client->serverAddr[0] !== "/") { // no unix domain socket
            $net = @\inet_pton($client->clientAddr);
            if (isset($net[4])) {
                $net = substr($net, 0, 7 /* /56 block */);
            }
            $this->clientsPerIP[$net]--;
            assert($this->logDebug("Close {$client->clientAddr}:{$client->clientPort} #{$client->id}"));
        } else {
            assert($this->logDebug("Close connection on {$client->serverAddr} #{$client->id}"));
        }

        if ($client->bufferDeferred) {
            $ex = new ClientException("Client forcefully closed");
            $client->bufferDeferred->fail($ex);
        }
    }

    private function clear(Internal\Client $client) {
        $client->requestParser = null;
        $client->onWriteDrain = null;

        Loop::cancel($client->readWatcher);
        Loop::cancel($client->writeWatcher);

        $this->clearConnectionTimeout($client);

        unset($this->clients[$client->id]);
        if ($this->stopDeferred && empty($this->clients)) {
            $this->stopDeferred->resolve();
        }
    }

    private function export(Internal\Client $client, Response $response) {
        $this->clear($client);
        $client->isExported = true;

        assert($this->logDebug("Export {$client->clientAddr}:{$client->clientPort}"));

        $net = @\inet_pton($client->clientAddr);
        if (isset($net[4])) {
            $net = substr($net, 0, 7 /* /56 block */);
        }

        $clientCount = &$this->clientCount;
        $clientsPerIP = &$this->clientsPerIP[$net];

        $closer = static function () use (&$clientCount, &$clientsPerIP) {
            $clientCount--;
            $clientsPerIP--;
        };

        assert($closer = (function () use ($client, $closer, &$clientCount, &$clientsPerIP) {
            $logger = $this->logger;
            $message = "Close {$client->clientAddr}:{$client->clientPort}";
            return static function () use ($closer, &$clientCount, &$clientsPerIP, $logger, $message) {
                $closer();
                $logger->log(Logger::DEBUG, $message);
            };
        })());

        $response->export(new Internal\DetachedSocket($closer, $client->socket));
    }

    private function dropPrivileges() {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return;
        }

        $user = $this->options->user;
        if (!extension_loaded("posix")) {
            if ($user !== null) {
                throw new \RuntimeException("Posix extension must be enabled to switch to user '{$user}'!");
            }
            $this->logger->warning("Posix extension not enabled, be sure not to run your server as root!");
            return;
        }

        if (posix_geteuid() === 0) {
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
            "host" => $this->host,
            "ticker" => $this->ticker,
            "observers" => $this->observers,
            "acceptWatcherIds" => $this->acceptWatcherIds,
            "boundServers" => $this->boundServers,
            "pendingTlsStreams" => $this->pendingTlsStreams,
            "clients" => $this->clients,
            "connectionTimeouts" => $this->connectionTimeouts,
            "stopPromise" => $this->stopDeferred ? $this->stopDeferred->promise() : null,
        ];
    }
}
