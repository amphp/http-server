<?php

namespace Amp\Http\Server;

use Amp\Coroutine;
use Amp\Failure;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Driver\ClientFactory;
use Amp\Http\Server\Driver\DefaultClientFactory;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Driver\HttpDriverFactory;
use Amp\Http\Server\Driver\TimeoutCache;
use Amp\Http\Server\Middleware\CompressionMiddleware;
use Amp\Loop;
use Amp\MultiReasonException;
use Amp\Promise;
use Amp\Socket;
use Amp\Socket\Server as SocketServer;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;

final class HttpServer
{
    public const STOPPED = 0;
    public const STARTING = 1;
    public const STARTED = 2;
    public const STOPPING = 3;

    public const STATES = [
        self::STOPPED => "STOPPED",
        self::STARTING => "STARTING",
        self::STARTED => "STARTED",
        self::STOPPING => "STOPPING",
    ];

    public const DEFAULT_SHUTDOWN_TIMEOUT = 3000;

    /** @var int */
    private $state = self::STOPPED;

    /** @var Options */
    private $options;

    /** @var RequestHandler */
    private $requestHandler;

    /** @var ErrorHandler */
    private $errorHandler;

    /** @var ClientFactory */
    private $clientFactory;

    /** @var HttpDriverFactory */
    private $driverFactory;

    /** @var PsrLogger */
    private $logger;

    /** @var \SplObjectStorage */
    private $observers;

    /** @var string[] */
    private $acceptWatcherIds = [];

    /** @var resource[] Server sockets. */
    private $boundServers = [];

    /** @var Client[] */
    private $clients = [];

    /** @var int */
    private $clientCount = 0;

    /** @var int[] */
    private $clientsPerIP = [];

    /** @var TimeoutCache */
    private $timeoutCache;

    /** @var string */
    private $timeoutWatcher;

    /**
     * @param SocketServer[] $servers
     * @param Options|null   $options Null creates an Options object with all default options.
     *
     * @throws \Error
     * @throws \TypeError If $servers contains anything other than instances of `Amp\Socket\Server`.
     */
    public function __construct(
        array $servers,
        RequestHandler $requestHandler,
        PsrLogger $logger,
        Options $options = null
    ) {
        foreach ($servers as $server) {
            if (!$server instanceof SocketServer) {
                throw new \TypeError(\sprintf("Only instances of %s should be given", SocketServer::class));
            }

            $this->boundServers[$server->getAddress()->toString()] = $server->getResource();
        }

        if (!$servers) {
            throw new \Error("Argument 1 can't be an empty array");
        }

        $this->logger = $logger;
        $this->options = $options ?? new Options;
        $this->clientFactory = new DefaultClientFactory;
        $this->timeoutCache = new TimeoutCache;

        if ($this->options->isCompressionEnabled()) {
            if (!\extension_loaded('zlib')) {
                $this->logger->warning(
                    "The zlib extension is not loaded which prevents using compression. " .
                    "Either activate the zlib extension or disable compression in the server's options."
                );
            } else {
                $requestHandler = Middleware\stack($requestHandler, new CompressionMiddleware);
            }
        }

        $this->requestHandler = $requestHandler;

        $this->timeoutWatcher = Loop::repeat(1000, \Closure::fromCallable([$this, 'checkClientTimeouts']));
        Loop::disable($this->timeoutWatcher);

        $this->observers = new \SplObjectStorage;
        $this->observers->attach(new Internal\PerformanceRecommender);

        $this->errorHandler = new DefaultErrorHandler;
        $this->driverFactory = new DefaultHttpDriverFactory;
    }

    public function __destruct()
    {
        if ($this->timeoutWatcher) {
            Loop::cancel($this->timeoutWatcher);
        }
    }

    /**
     * Define a custom HTTP driver factory.
     *
     * @throws \Error If the server has started.
     */
    public function setDriverFactory(HttpDriverFactory $driverFactory): void
    {
        if ($this->state) {
            throw new \Error("Cannot set the driver factory after the server has started");
        }

        $this->driverFactory = $driverFactory;
    }

    /**
     * Define a custom Client factory.
     *
     * @throws \Error If the server has started.
     */
    public function setClientFactory(ClientFactory $clientFactory): void
    {
        if ($this->state) {
            throw new \Error("Cannot set the client factory after the server has started");
        }

        $this->clientFactory = $clientFactory;
    }

    /**
     * Set the error handler instance to be used for generating error responses.
     *
     * @throws \Error If the server has started.
     */
    public function setErrorHandler(ErrorHandler $errorHandler): void
    {
        if ($this->state) {
            throw new \Error("Cannot set the error handler after the server has started");
        }

        $this->errorHandler = $errorHandler;
    }

    /**
     * Retrieve the current server state.
     */
    public function getState(): int
    {
        return $this->state;
    }

    /**
     * Retrieve the server options object.
     */
    public function getOptions(): Options
    {
        return $this->options;
    }

    /**
     * Retrieve the error handler.
     */
    public function getErrorHandler(): ErrorHandler
    {
        return $this->errorHandler;
    }

    /**
     * Retrieve the logger.
     */
    public function getLogger(): PsrLogger
    {
        return $this->logger;
    }

    /**
     * Attach an observer.
     *
     * @throws \Error If the server has started.
     */
    public function attach(ServerObserver $observer): void
    {
        if ($this->state) {
            throw new \Error("Cannot attach observers after the server has started");
        }

        $this->observers->attach($observer);
    }

    /**
     * Start the server.
     *
     * @return Promise<void>
     */
    public function start(): Promise
    {
        try {
            if ($this->state === self::STOPPED) {
                return new Coroutine($this->doStart());
            }

            return new Failure(new \Error(
                "Cannot start server: already " . self::STATES[$this->state]
            ));
        } catch (\Throwable $uncaught) {
            return new Failure($uncaught);
        }
    }

    private function doStart(): \Generator
    {
        \assert($this->logger->debug("Starting") || true);

        if ($this->driverFactory instanceof ServerObserver) {
            $this->observers->attach($this->driverFactory);
        }

        if ($this->clientFactory instanceof ServerObserver) {
            $this->observers->attach($this->clientFactory);
        }

        if ($this->requestHandler instanceof ServerObserver) {
            $this->observers->attach($this->requestHandler);
        }

        if ($this->errorHandler instanceof ServerObserver) {
            $this->observers->attach($this->errorHandler);
        }

        $this->state = self::STARTING;

        $promises = [];
        foreach ($this->observers as $observer) {
            $promises[] = $observer->onStart($this, $this->logger, $this->errorHandler);
        }
        list($exceptions) = yield Promise\any($promises);

        if (!empty($exceptions)) {
            try {
                yield from $this->doStop(self::DEFAULT_SHUTDOWN_TIMEOUT);
            } finally {
                throw new MultiReasonException($exceptions, "onStart observer initialization failure");
            }
        }

        $this->state = self::STARTED;
        \assert($this->logger->debug("Started") || true);

        $protocols = $this->driverFactory->getApplicationLayerProtocols();

        $onAcceptable = \Closure::fromCallable([$this, 'onAcceptable']);
        foreach ($this->boundServers as $serverName => $server) {
            $context = \stream_context_get_options($server);
            $scheme = "http";

            if (isset($context["ssl"])) {
                $scheme = "https";

                if (Socket\hasTlsAlpnSupport()) {
                    \stream_context_set_option($server, "ssl", "alpn_protocols", \implode(", ", $protocols));
                } elseif ($protocols) {
                    $this->logger->alert("ALPN not supported with the installed version of OpenSSL");
                }
            }

            $this->acceptWatcherIds[$serverName] = Loop::onReadable($server, $onAcceptable);
            $this->logger->info("Listening on {$scheme}://{$serverName}/");
        }

        Loop::enable($this->timeoutWatcher);
    }

    private function onAcceptable(string $watcherId, $server): void
    {
        if (!$socket = @\stream_socket_accept($server, 0)) {
            return;
        }

        $client = $this->clientFactory->createClient(
            $socket,
            $this->requestHandler,
            $this->errorHandler,
            $this->logger,
            $this->options,
            $this->timeoutCache
        );

        \assert($this->logger->debug("Accept {$client->getRemoteAddress()} on " .
                "{$client->getLocalAddress()} #{$client->getId()}") || true);

        $ip = $net = $client->getRemoteAddress()->getHost();
        if (@\inet_pton($net) !== false && isset($net[4])) {
            $net = \substr($net, 0, 7 /* /56 block for IPv6 */);
        }

        if (!isset($this->clientsPerIP[$net])) {
            $this->clientsPerIP[$net] = 0;
        }

        $client->onClose(function (Client $client) use ($net) {
            unset($this->clients[$client->getId()]);

            if (--$this->clientsPerIP[$net] === 0) {
                unset($this->clientsPerIP[$net]);
            }

            --$this->clientCount;
        });

        if ($this->clientCount++ === $this->options->getConnectionLimit()) {
            $this->logger->warning("Client denied: too many existing connections");
            $client->close();
            return;
        }

        $clientCount = $this->clientsPerIP[$net]++;

        // Connections on localhost are excluded from the connections per IP setting.
        // Checks IPv4 loopback (127.x), IPv6 loopback (::1) and IPv4-to-IPv6 mapped loopback.
        // Also excludes all connections that are via unix sockets.
        if ($clientCount === $this->options->getConnectionsPerIpLimit()
            && $ip !== "::1" && \strncmp($ip, "127.", 4) !== 0 && $client->getLocalAddress()->getPort() !== null
            && \strncmp(\inet_pton($ip), '\0\0\0\0\0\0\0\0\0\0\xff\xff\7f', 31)
        ) {
            $packedIp = @\inet_pton($ip);

            if (isset($packedIp[4])) {
                $ip .= "/56";
            }

            $this->logger->warning("Client denied: too many existing connections from {$ip}");

            $client->close();
            return;
        }

        $this->clients[$client->getId()] = $client;

        $client->start($this->driverFactory);
    }

    /**
     * Stop the server.
     *
     * @param int $timeout Number of milliseconds to allow clients to gracefully shutdown before forcefully closing.
     *
     * @return Promise
     */
    public function stop(int $timeout = self::DEFAULT_SHUTDOWN_TIMEOUT): Promise
    {
        switch ($this->state) {
            case self::STARTED:
                return new Coroutine($this->doStop($timeout));
            case self::STOPPED:
                return new Success;
            default:
                return new Failure(new \Error(
                    "Cannot stop server: currently " . self::STATES[$this->state]
                ));
        }
    }

    private function doStop(int $timeout): \Generator
    {
        \assert($this->logger->debug("Stopping") || true);
        $this->state = self::STOPPING;

        foreach ($this->acceptWatcherIds as $watcherId) {
            Loop::cancel($watcherId);
        }
        $this->boundServers = [];
        $this->acceptWatcherIds = [];

        $promises = [];
        foreach ($this->observers as $observer) {
            $promises[] = $observer->onStop($this);
        }

        list($exceptions) = yield Promise\any($promises);

        $promises = [];
        foreach ($this->clients as $client) {
            $promises[] = $client->stop($timeout);
        }

        yield Promise\any($promises);

        \assert($this->logger->debug("Stopped") || true);
        $this->state = self::STOPPED;

        if (!empty($exceptions)) {
            throw new MultiReasonException($exceptions, "onStop observer failure");
        }

        Loop::disable($this->timeoutWatcher);
    }

    private function checkClientTimeouts(): void
    {
        $now = \time();

        while ($id = $this->timeoutCache->extract($now)) {
            \assert(isset($this->clients[$id]), "Timeout cache contains an invalid client ID");

            $client = $this->clients[$id];

            if ($client->isWaitingOnResponse()) {
                $this->timeoutCache->update($id, $now + 1);
                continue;
            }

            // Client is either idle or taking too long to send request, so simply close the connection.
            $client->close();
        }
    }

    public function __debugInfo(): array
    {
        return [
            "state" => $this->state,
            "observers" => $this->observers,
            "acceptWatcherIds" => $this->acceptWatcherIds,
            "boundServers" => $this->boundServers,
            "clients" => $this->clients,
            "connectionTimeouts" => $this->timeoutCache,
        ];
    }
}
