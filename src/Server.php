<?php

namespace Amp\Http\Server;

use Amp\CallableMaker;
use Amp\Coroutine;
use Amp\Failure;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Driver\HttpDriverFactory;
use Amp\Http\Server\Driver\RemoteClient;
use Amp\Http\Server\Driver\SystemTimeReference;
use Amp\Http\Server\Driver\TimeoutCache;
use Amp\Http\Server\Driver\TimeReference;
use Amp\Http\Server\Middleware\CompressionMiddleware;
use Amp\Loop;
use Amp\MultiReasonException;
use Amp\Promise;
use Amp\Socket\Server as SocketServer;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;

final class Server {
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

    const DEFAULT_SHUTDOWN_TIMEOUT = 3000;

    /** @var int */
    private $state = self::STOPPED;

    /** @var Options */
    private $options;

    /** @var RequestHandler */
    private $requestHandler;

    /** @var ErrorHandler */
    private $errorHandler;

    /** @var HttpDriverFactory */
    private $driverFactory;

    /** @var PsrLogger */
    private $logger;

    /** @var TimeReference */
    private $timeReference;

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
    private $timeouts;

    /**
     * @param SocketServer[] $servers
     * @param RequestHandler $requestHandler
     * @param PsrLogger      $logger
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

            $this->boundServers[$server->getAddress()] = $server->getResource();
        }

        if (!$servers) {
            throw new \Error("Argument 1 can't be an empty array");
        }

        $this->logger = $logger;
        $this->options = $options ?? new Options;
        $this->timeReference = new SystemTimeReference;
        $this->timeouts = new TimeoutCache(
            $this->timeReference,
            $this->options->getConnectionTimeout()
        );

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

        $this->timeReference->onTimeUpdate($this->callableFromInstanceMethod("timeoutKeepAlives"));

        $this->observers = new \SplObjectStorage;
        $this->observers->attach(new Internal\PerformanceRecommender);

        $this->errorHandler = new DefaultErrorHandler;
        $this->driverFactory = new DefaultHttpDriverFactory;
    }

    /**
     * Define a custom HTTP driver factory.
     *
     * @param HttpDriverFactory $driverFactory
     *
     * @throws \Error If the server has started.
     */
    public function setDriverFactory(HttpDriverFactory $driverFactory) {
        if ($this->state) {
            throw new \Error("Cannot set the driver factory after the server has started");
        }

        $this->driverFactory = $driverFactory;
    }

    /**
     * Set the error handler instance to be used for generating error responses.
     *
     * @param ErrorHandler $errorHandler
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
     * @return Options
     */
    public function getOptions(): Options {
        return $this->options;
    }

    /**
     * Retrieve the error handler.
     *
     * @return ErrorHandler
     */
    public function getErrorHandler(): ErrorHandler {
        return $this->errorHandler;
    }

    /**
     * Retrieve the logger.
     *
     * @return PsrLogger
     */
    public function getLogger(): PsrLogger {
        return $this->logger;
    }

    /**
     * @return TimeReference
     */
    public function getTimeReference(): TimeReference {
        return $this->timeReference;
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
     * Start the server.
     *
     * @return \Amp\Promise
     */
    public function start(): Promise {
        try {
            if ($this->state === self::STOPPED) {
                return new Coroutine($this->doStart());
            }

            return new Failure(new \Error(
                "Cannot start server: already ".self::STATES[$this->state]
            ));
        } catch (\Throwable $uncaught) {
            return new Failure($uncaught);
        }
    }

    private function doStart(): \Generator {
        \assert($this->logger->debug("Starting") || true);

        $this->observers->attach($this->timeReference);

        if ($this->driverFactory instanceof ServerObserver) {
            $this->observers->attach($this->driverFactory);
        }

        if ($this->requestHandler instanceof ServerObserver) {
            $this->observers->attach($this->requestHandler);
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

        $onAcceptable = $this->callableFromInstanceMethod("onAcceptable");
        foreach ($this->boundServers as $serverName => $server) {
            $context = \stream_context_get_options($server);
            $scheme = "http";

            if (isset($context["ssl"])) {
                $scheme = "https";

                if (self::hasAlpnSupport()) {
                    \stream_context_set_option($server, "ssl", "alpn_protocols", \implode(", ", $protocols));
                } elseif ($protocols) {
                    $this->logger->alert("ALPN not supported with the installed version of OpenSSL");
                }
            }

            $this->acceptWatcherIds[$serverName] = Loop::onReadable($server, $onAcceptable);
            $this->logger->info("Listening on {$scheme}://{$serverName}/");
        }
    }

    private function onAcceptable(string $watcherId, $server) {
        if (!$socket = @\stream_socket_accept($server, 0)) {
            return;
        }

        $client = new RemoteClient(
            $socket,
            $this->requestHandler,
            $this->errorHandler,
            $this->logger,
            $this->options,
            $this->timeouts
        );

        \assert($this->logger->debug("Accept {$client->getRemoteAddress()}:{$client->getRemotePort()} on " .
            "{$client->getLocalAddress()}:{$client->getLocalPort()} #{$client->getId()}") || true);

        $net = $client->getRemoteAddress();
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
            \assert($this->logger->debug("Client denied: too many existing connections") || true);
            $client->close();
            return;
        }

        $ip = $client->getRemoteAddress();
        $clientCount = $this->clientsPerIP[$net]++;

        // Connections on localhost are excluded from the connections per IP setting.
        // Checks IPv4 loopback (127.x), IPv6 loopback (::1) and IPv4-to-IPv6 mapped loopback.
        // Also excludes all connections that are via unix sockets.
        if ($clientCount === $this->options->getConnectionsPerIpLimit()
            && $ip !== "::1" && \strncmp($ip, "127.", 4) !== 0 && !$client->isUnix()
            && \strncmp(\inet_pton($ip), '\0\0\0\0\0\0\0\0\0\0\xff\xff\7f', 31)
        ) {
            \assert(function () use ($ip) {
                $addr = $ip;
                $packedIp = @\inet_pton($ip);

                if (isset($packedIp[4])) {
                    $addr .= "/56";
                }

                $this->logger->debug("Client denied: too many existing connections from {$addr}");

                return true;
            });

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
    public function stop(int $timeout = self::DEFAULT_SHUTDOWN_TIMEOUT): Promise {
        switch ($this->state) {
            case self::STARTED:
                return new Coroutine($this->doStop($timeout));
            case self::STOPPED:
                return new Success;
            default:
                return new Failure(new \Error(
                    "Cannot stop server: currently ".self::STATES[$this->state]
                ));
        }
    }

    private function doStop(int $timeout): \Generator {
        \assert($this->logger->debug("Stopping") || true);
        $this->state = self::STOPPING;

        foreach ($this->acceptWatcherIds as $watcherId) {
            Loop::cancel($watcherId);
        }
        $this->boundServers = [];
        $this->acceptWatcherIds = [];

        $promises = [];
        foreach ($this->clients as $client) {
            $promises[] = $client->stop($timeout);
        }

        yield Promise\any($promises);

        $promises = [];
        foreach ($this->observers as $observer) {
            $promises[] = $observer->onStop($this);
        }

        list($exceptions) = yield Promise\any($promises);

        \assert($this->logger->debug("Stopped") || true);
        $this->state = self::STOPPED;

        if (!empty($exceptions)) {
            throw new MultiReasonException($exceptions, "onStop observer failure");
        }
    }

    private function timeoutKeepAlives(int $now) {
        foreach ($this->timeouts as $id => $expiresAt) {
            if ($now < $expiresAt) {
                break;
            }

            $client = $this->clients[$id];

            // Client is either idle or taking too long to send request, so simply close the connection.
            $client->close();
        }
    }

    public function __debugInfo() {
        return [
            "state" => $this->state,
            "timeReference" => $this->timeReference,
            "observers" => $this->observers,
            "acceptWatcherIds" => $this->acceptWatcherIds,
            "boundServers" => $this->boundServers,
            "clients" => $this->clients,
            "connectionTimeouts" => $this->timeouts,
        ];
    }

    /**
     * @see https://wiki.openssl.org/index.php/Manual:OPENSSL_VERSION_NUMBER(3)
     * @return bool
     */
    private static function hasAlpnSupport(): bool {
        if (!\defined("OPENSSL_VERSION_NUMBER")) {
            return false;
        }

        return \OPENSSL_VERSION_NUMBER >= 0x10002000;
    }
}
