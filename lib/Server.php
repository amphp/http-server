<?php

namespace Aerys;

use Aerys\Cookie\Cookie;
use Aerys\Internal\Client;
use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\IteratorStream;
use Amp\CallableMaker;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Emitter;
use Amp\Failure;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\Promise\all;
use function Amp\Promise\any;
use function Amp\Promise\timeout;

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

    /** @var int */
    private $state = self::STOPPED;

    /** @var \Aerys\Options */
    private $options;

    /** @var \Aerys\Internal\Host */
    private $host;

    /** @var \Aerys\Internal\HttpDriver */
    private $httpDriver;

    /** @var \Aerys\Responder|null Responder instance built from given responders and middleware */
    private $responder;

    /** @var mixed[] */
    private $actions = [];

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
     * @param \Aerys\Options|null $options
     * @param \Psr\Log\LoggerInterface|null $logger Null automatically uses an instance of \Aerys\ConsoleLogger.
     * @param \Aerys\Internal\HttpDriver $driver Internal parameter used for testing.
     */
    public function __construct(Options $options = null, PsrLogger $logger = null, Internal\HttpDriver $driver = null) {
        $this->host = new Internal\Host;
        $this->options = $options ?? new Options;
        $this->logger = $logger ?? new ConsoleLogger(new Console);
        $this->ticker = new Internal\Ticker($this->logger);
        $this->observers = new \SplObjectStorage;
        $this->observers->attach($this->ticker);
        $this->ticker->use($this->callableFromInstanceMethod("timeoutKeepAlives"));
        $this->nullBody = new NullBody;

        // private callables that we pass to external code //
        $this->onAcceptable = $this->callableFromInstanceMethod("onAcceptable");
        $this->onUnixSocketAcceptable = $this->callableFromInstanceMethod("onUnixSocketAcceptable");
        $this->negotiateCrypto = $this->callableFromInstanceMethod("negotiateCrypto");
        $this->onReadable = $this->callableFromInstanceMethod("onReadable");
        $this->onWritable = $this->callableFromInstanceMethod("onWritable");
        $this->onResponseDataDone = $this->callableFromInstanceMethod("onResponseDataDone");

        $this->httpDriver = $driver ?? new Internal\Http1Driver;
        $this->httpDriver->setup($this->createHttpDriverHandlers(), $this->callableFromInstanceMethod("writeResponse"));
    }

    /**
     * Use a callable request action, Responder, or Middleware.
     *
     * Actions are invoked to service requests in the order in which they are added.
     *
     * If the action is an instance of ServerObserver it is automatically attached.
     *
     * @param callable|Responder|Middleware|Bootable $action
     *
     * @return self
     *
     * @throws \Error If $action is not one of the types above.
     */
    public function use($action): self {
        if ($this->state) {
            throw new \Error("Cannot add actions after the server has been started");
        }

        if (!\is_callable($action)
            && !$action instanceof Responder
            && !$action instanceof Middleware
            && !$action instanceof Bootable
        ) {
            throw new \TypeError(
                \sprintf(
                    "%s() requires a callable action or an instance of %s, %s, or %s",
                    __METHOD__,
                    Responder::class,
                    Middleware::class,
                    Bootable::class
                )
            );
        }

        $this->actions[] = $action;

        if ($action instanceof ServerObserver) {
            $this->observers->attach($action);
        }

        return $this;
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
     * @return self
     */
    public function expose(string $address, int $port = 0): self {
        $this->host->expose($address, $port);
        return $this;
    }

    /**
     * Define TLS encryption settings for this server.
     *
     * @param string|\Amp\Socket\Certificate|\Amp\Socket\ServerTlsContext $certificate A string path pointing to your
     *     SSL/TLS certificate, a Certificate object, or a ServerTlsContext object
     *
     * @return self
     */
    public function encrypt($certificate): self {
        $this->host->encrypt($certificate);
        return $this;
    }

    /**
     * Retrieve the current server state.
     *
     * @return int
     */
    public function state(): int {
        return $this->state;
    }

    /**
     * Retrieve a server option value.
     *
     * @param string $option The option to retrieve
     * @throws \Error on unknown option
     */
    public function getOption(string $option) {
        return $this->options->{$option};
    }

    /**
     * Assign a server option value.
     *
     * @param string $option The option to retrieve
     * @param mixed $newValue
     * @throws \Error on unknown option
     * @return void
     */
    public function setOption(string $option, $newValue) {
        \assert($this->state < self::STARTED);
        $this->options->{$option} = $newValue;
    }

    /**
     * Attach an observer.
     *
     * @param ServerObserver $observer
     * @return void
     */
    public function attach(ServerObserver $observer) {
        $this->observers->attach($observer);
    }

    /**
     * Detach an Observer.
     *
     * @param ServerObserver $observer
     * @return void
     */
    public function detach(ServerObserver $observer) {
        $this->observers->detach($observer);
    }

    /**
     * Notify observers of a server state change.
     *
     * Resolves to an indexed any() Promise combinator array.
     *
     * @return Promise
     */
    private function notify(): Promise {
        $promises = [];
        foreach ($this->observers as $observer) {
            $promises[] = $observer->update($this);
        }

        $promise = any($promises);
        $promise->onResolve(function ($error, $result) {
            // $error is always empty because an any() combinator Promise never fails.
            // Instead we check the error array at index zero in the two-item any() $result
            // and log as needed.
            list($observerErrors) = $result;
            foreach ($observerErrors as $error) {
                $this->logger->error($error);
            }
        });
        return $promise;
    }

    /**
     * Start the server.
     *
     * @param callable(array) $bindSockets is passed the $address => $context map
     * @return \Amp\Promise
     */
    public function start(callable $bindSockets = null): Promise {
        try {
            if ($this->state === self::STOPPED) {
                $this->responder = $this->buildResponder();

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

    /**
     * Returns an instance of \Aerys\Responder built from the responders and middleware used on this server.
     *
     * @return \Aerys\Responder
     */
    private function buildResponder(): Responder {
        $bootLoader = function (Bootable $bootable) {
            $booted = $bootable->boot($this, $this->logger);
            if ($booted !== null
                && !$booted instanceof Responder
                && !$booted instanceof Middleware
                && !is_callable($booted)
            ) {
                throw new \Error(\sprintf(
                    "Any return value of %s::boot() must be callable or an instance of %s or %s",
                    \str_replace("\0", "@", \get_class($bootable)),
                    Responder::class,
                    Middleware::class
                ));
            }
            return $booted ?? $bootable;
        };

        $responders = [];
        $middlewares = [];

        foreach ($this->actions as $key => $action) {
            if ($action instanceof Bootable) {
                $action = $bootLoader($action);
            }

            if ($action instanceof Middleware) {
                $middlewares[] = $action;
            }

            if (\is_callable($action)) {
                $action = new CallableResponder($action);
            }

            if ($action instanceof Responder) {
                $responders[] = $action;
            }
        }

        if (empty($responders)) {
            $responder = new CallableResponder(static function (): Response {
                return new Response\HtmlResponse("<html><body><h1>It works!</h1></body>");
            });
        } elseif (\count($responders) === 1) {
            $responder = $responders[0];
        } else {
            $responder = new TryResponder;
            foreach ($responders as $action) {
                $responder->addResponder($action);
            }
        }

        return MiddlewareResponder::create($responder, $middlewares);
    }

    private function createHttpDriverHandlers() {
        return [
            Internal\HttpDriver::RESULT => $this->callableFromInstanceMethod("onParsedMessage"),
            Internal\HttpDriver::ENTITY_HEADERS => $this->callableFromInstanceMethod("onParsedEntityHeaders"),
            Internal\HttpDriver::ENTITY_PART => $this->callableFromInstanceMethod("onParsedEntityPart"),
            Internal\HttpDriver::ENTITY_RESULT => $this->callableFromInstanceMethod("onParsedMessageWithEntity"),
            Internal\HttpDriver::SIZE_WARNING => $this->callableFromInstanceMethod("onEntitySizeWarning"),
            Internal\HttpDriver::ERROR => $this->callableFromInstanceMethod("onParseError"),
        ];
    }

    private function doStart(callable $bindSockets): \Generator {
        assert($this->logDebug("starting"));

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
        $notifyResult = yield $this->notify();
        if ($hadErrors = (bool) $notifyResult[0]) {
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
            $onAcceptable = $this->onAcceptable;
            if (!strncmp($serverName, "unix://", 7)) {
                $onAcceptable = $this->onUnixSocketAcceptable;
            }
            $this->acceptWatcherIds[$serverName] = Loop::onReadable($server, $onAcceptable);
            $this->logger->info("Listening on {$serverName}");
        }

        try {
            return yield $this->notify();
        } catch (\Throwable $exception) {
            yield from $this->doStop();
            throw new \RuntimeException("Server::STARTED observer initialization failure", 0, $exception);
        }
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

        \assert($this->logDebug("accept {$peerName} on " . stream_socket_get_name($client, false) . " #" . (int) $client));

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

        \assert($this->logDebug("accept connection on " . stream_socket_get_name($client, false) . " #" . (int) $client));

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
                return timeout($stopPromise, $this->options->shutdownTimeout);
            case self::STOPPED:
                return new Success;
            default:
                return new Failure(new \Error(
                    "Cannot stop server: currently ".self::STATES[$this->state]
                ));
        }
    }

    private function doStop(): \Generator {
        assert($this->logDebug("stopping"));
        $this->state = self::STOPPING;

        foreach ($this->acceptWatcherIds as $watcherId) {
            Loop::cancel($watcherId);
        }
        $this->boundServers = [];
        $this->acceptWatcherIds = [];
        foreach ($this->pendingTlsStreams as list(, $socket)) {
            $this->failCryptoNegotiation($socket, key($this->clientsPerIP) /* doesn't matter after stop */);
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

        yield all([$this->stopDeferred->promise(), $this->notify()]);

        assert($this->logDebug("stopped"));
        $this->state = self::STOPPED;
        $this->stopDeferred = null;

        yield $this->notify();
    }

    private function importClient($socket, $ip, $port) {
        $client = new Client;
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

    private function writeResponse(Client $client, string $data, bool $final = false) {
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

    private function onResponseDataDone(Client $client) {
        if ($client->shouldClose || (--$client->pendingResponses == 0 && $client->isDead == Client::CLOSED_RD)) {
            $this->close($client);
        } elseif (!($client->isDead & Client::CLOSED_RD)) {
            $this->renewConnectionTimeout($client);
        }
    }

    private function onWritable(string $watcherId, $socket, Client $client) {
        $bytesWritten = @\fwrite($socket, $client->writeBuffer);
        if ($bytesWritten === false || ($bytesWritten === 0 && (!\is_resource($socket) || @\feof($socket)))) {
            if ($client->isDead === Client::CLOSED_RD) {
                $this->close($client);
            } else {
                $client->isDead = Client::CLOSED_WR;
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
            // do not close in case some longer response is taking longer, but do in case bodyEmitters aren't fulfilled
            if ($client->pendingResponses > \count($client->bodyEmitters)) {
                $this->clearConnectionTimeout($client);
            } else {
                // timeouts are only active while Client is doing nothing (not sending nor receving) and no pending writes, hence we can just fully close here
                $this->close($client);
            }
        }
    }

    private function renewConnectionTimeout(Client $client) {
        $timeoutAt = $this->ticker->currentTime + $this->options->connectionTimeout;
        // DO NOT remove the call to unset(); it looks superfluous but it's not.
        // Keep-alive timeout entries must be ordered by value. This means that
        // it's not enough to replace the existing map entry -- we have to remove
        // it completely and push it back onto the end of the array to maintain the
        // correct order.
        unset($this->connectionTimeouts[$client->id]);
        $this->connectionTimeouts[$client->id] = $timeoutAt;
    }

    private function clearConnectionTimeout(Client $client) {
        unset($this->connectionTimeouts[$client->id]);
    }

    private function onReadable(string $watcherId, $socket, Client $client) {
        $data = @\stream_get_contents($socket, $this->options->ioGranularity);
        if ($data !== "") {
            $this->renewConnectionTimeout($client);
            $client->requestParser->send($data);
            return;
        }

        if (!\is_resource($socket) || @\feof($socket)) {
            if ($client->isDead === Client::CLOSED_WR || $client->pendingResponses == 0) {
                $this->close($client);
                return;
            }

            $client->isDead = Client::CLOSED_RD;
            Loop::cancel($watcherId);
            if ($client->bodyEmitters) {
                $ex = new ClientException;
                foreach ($client->bodyEmitters as $key => $emitter) {
                    $emitter->fail($ex);
                    $client->bodyEmitters[$key] = new Emitter;
                }
            }
        }
    }

    private function onParsedMessage(Internal\ServerRequest $ireq) {
        if ($this->options->normalizeMethodCase) {
            $ireq->method = strtoupper($ireq->method);
        }

        assert($this->logDebug(sprintf(
            "%s %s HTTP/%s @ %s:%s%s",
            $ireq->method,
            $ireq->uri,
            $ireq->protocol,
            $ireq->client->clientAddr,
            $ireq->client->clientPort,
            ""//empty($parseResult["server_push"]) ? "" : " (server-push via {$parseResult["server_push"]})"
        )));

        $ireq->client->remainingRequests--;

        $ireq->time = $this->ticker->currentTime;
        $ireq->httpDate = $this->ticker->currentHttpDate;

        if (!isset($ireq->body)) {
            $ireq->body = $this->nullBody;
        }

        if (!empty($ireq->headers["cookie"])) { // @TODO delay initialization
            $cookies = \array_filter(\array_map([Cookie::class, "fromHeader"], $ireq->headers["cookie"]));
            foreach ($cookies as $cookie) {
                $ireq->cookies[$cookie->getName()] = $cookie;
            }
        }

        $this->respond($ireq);
    }

    private function onParsedEntityHeaders(Internal\ServerRequest $ireq) {
        $ireq->client->bodyEmitters[$ireq->streamId] = $bodyEmitter = new Emitter;
        $ireq->body = new Body(new IteratorStream($bodyEmitter->iterate()));

        $this->onParsedMessage($ireq);
    }

    private function onParsedEntityPart(Client $client, $body, int $streamId = 0) {
        $client->bodyEmitters[$streamId]->emit($body);
    }

    private function onParsedMessageWithEntity(Client $client, int $streamId = 0) {
        $emitter = $client->bodyEmitters[$streamId];
        unset($client->bodyEmitters[$streamId]);
        $emitter->complete();
        // @TODO Update trailer headers if present

        // Don't respond() because we always start the response when headers arrive
    }

    private function onEntitySizeWarning(Client $client, int $streamId = 0) {
        $emitter = $client->bodyEmitters[$streamId];
        $client->bodyEmitters[$streamId] = new Emitter;
        $emitter->fail(new ClientSizeException);
    }

    private function onParseError(Client $client, int $status, string $message) {
        $this->clearConnectionTimeout($client);

        if ($client->bodyEmitters) {
            $client->shouldClose = true;
            $this->writeResponse($client, true);
            return;
        }

        $client->pendingResponses++;

        $ireq = new Internal\ServerRequest;
        $ireq->client = $client;
        $ireq->time = $this->ticker->currentTime;
        $ireq->httpDate = $this->ticker->currentHttpDate;

        $headers = ["Connection" => "close"];
        $response = new Response\EmptyResponse($headers, $status);

        $generator = $this->sendResponse($ireq, $response);

        Promise\rethrow(new Coroutine($generator));
    }

    private function setTrace(Internal\ServerRequest $ireq) {
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

    private function respond(Internal\ServerRequest $ireq) {
        $ireq->client->pendingResponses++;

        if ($this->stopDeferred) {
            $generator = $this->sendResponse($ireq, $this->makePreAppServiceUnavailableResponse());
        } elseif (!\in_array($ireq->method, $this->options->allowedMethods)) {
            $generator = $this->sendResponse($ireq, $this->makePreAppMethodNotAllowedResponse());
        } elseif ($ireq->method === "TRACE") {
            $this->setTrace($ireq);
            $generator = $this->sendResponse($ireq, $this->makePreAppTraceResponse($ireq));
        } elseif ($ireq->method === "OPTIONS" && $ireq->target === "*") {
            $generator = $this->sendResponse($ireq, $this->makePreAppOptionsResponse());
        } else {
            $generator = $this->tryResponder($ireq);
        }

        Promise\rethrow(new Coroutine($generator));
    }

    private function makePreAppServiceUnavailableResponse(): Response {
        $status = HttpStatus::SERVICE_UNAVAILABLE;
        $headers = [
            "Connection" => "close",
        ];
        return new Response\EmptyResponse($headers, $status);
    }

    private function makePreAppMethodNotAllowedResponse(): Response {
        $status = HttpStatus::METHOD_NOT_ALLOWED;
        $headers = [
            "Connection" => "close",
            "Allow" => implode(", ", $this->options->allowedMethods),
        ];
        return new Response\EmptyResponse($headers, $status);
    }

    private function makePreAppTraceResponse(Internal\ServerRequest $request): Response {
        $stream = new InMemoryStream($request->locals['aerys.trace']);
        return new Response($stream, ["Content-Type" => "message/http"]);
    }

    private function makePreAppOptionsResponse(): Response {
        return new Response\EmptyResponse(["Allow" => implode(", ", $this->options->allowedMethods)]);
    }

    /**
     * @param \Aerys\Internal\ServerRequest $ireq
     * @param \Aerys\Responder $responder
     *
     * @return \Generator
     */
    private function tryResponder(Internal\ServerRequest $ireq): \Generator {
        $request = new Request($ireq);

        try {
            $response = yield $this->responder->respond($request);

            if (!$response instanceof Response) {
                throw new \Error("At least one request handler must return an instance of " . Response::class);
            }
        } catch (\Throwable $error) {
            $this->logger->error($error);
            // @TODO ClientExceptions?
            $response = $this->makeErrorResponse($error, $request);
        }

        yield from $this->sendResponse($ireq, $response);
    }

    /**
     * @param \Aerys\Internal\ServerRequest $ireq
     * @param \Aerys\Response $response
     *
     * @return \Generator
     */
    private function sendResponse(Internal\ServerRequest $ireq, Response $response): \Generator {
        $responseWriter = $ireq->client->httpDriver->writer($ireq, $response);
        $body = $response->getBody();

        try {
            do {
                $chunk = yield $body->read();

                if ($ireq->client->isDead & Client::CLOSED_WR) {
                    foreach ($ireq->onClose as $onClose) {
                        $onClose();
                    }

                    $responseWriter->send(null);
                    return;
                }

                $responseWriter->send($chunk); // Sends null when stream closes.
            } while ($chunk !== null);
        } catch (\Throwable $exception) {
            // Reading response body failed, abort writing the response to the client.
            $this->logger->error($exception);
            $responseWriter->send(null);
        }

        try {
            foreach ($ireq->onClose as $onClose) {
                $onClose();
            }

            if ($response->isDetached()) {
                $responseWriter->send(null);

                $this->export($ireq->client, $response);
            }
        } catch (\Throwable $exception) {
            $this->logger->error($exception);
        }
    }

    private function makeErrorResponse(\Throwable $error, Request $request): Response {
        $status = HttpStatus::INTERNAL_SERVER_ERROR;
        $message = $this->options->debug
            ? "<pre>" . \htmlspecialchars($error) . "</pre>"
            : "<p>Something went wrong ...</p>";

        $body = makeGenericBody($status, [
            "sub_heading" =>"Requested: " . $request->getUri(),
            "message" => $message,
        ]);

        return new Response\HtmlResponse($body, [], $status);
    }

    private function close(Client $client) {
        $this->clear($client);
        assert($client->isDead !== Client::CLOSED_RDWR);
        // ensures a TCP FIN frame is sent even if other processes (forks) have inherited the fd
        @\stream_socket_shutdown($client->socket, \STREAM_SHUT_RDWR);
        @fclose($client->socket);
        $client->isDead = Client::CLOSED_RDWR;

        $this->clientCount--;
        if ($client->serverAddr[0] != "/") { // no unix domain socket
            $net = @\inet_pton($client->clientAddr);
            if (isset($net[4])) {
                $net = substr($net, 0, 7 /* /56 block */);
            }
            $this->clientsPerIP[$net]--;
            assert($this->logDebug("close {$client->clientAddr}:{$client->clientPort} #{$client->id}"));
        } else {
            assert($this->logDebug("close connection on {$client->serverAddr} #{$client->id}"));
        }

        if ($client->bodyEmitters) {
            $ex = new ClientException;
            foreach ($client->bodyEmitters as $key => $emitter) {
                $emitter->fail($ex);
                $client->bodyEmitters[$key] = new Emitter;
            }
        }

        if ($client->bufferDeferred) {
            $ex = $ex ?? new ClientException;
            $client->bufferDeferred->fail($ex);
        }
    }

    private function clear(Client $client) {
        $client->requestParser = null; // break cyclic reference
        $client->onWriteDrain = null;
        Loop::cancel($client->readWatcher);
        Loop::cancel($client->writeWatcher);
        $this->clearConnectionTimeout($client);
        unset($this->clients[$client->id]);
        if ($this->stopDeferred && empty($this->clients)) {
            $this->stopDeferred->resolve();
        }
    }

    private function export(Client $client, Response $response) {
        $this->clear($client);
        $client->isExported = true;

        assert($this->logDebug("export {$client->clientAddr}:{$client->clientPort}"));

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
            $message = "close {$client->clientAddr}:{$client->clientPort}";
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
