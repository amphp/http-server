<?php

namespace Aerys;

use Amp\CallableMaker;
use Amp\Coroutine;
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

    /** @var int */
    private $state = self::STOPPED;

    /** @var Options */
    private $options;

    /** @var \Aerys\Internal\Host */
    private $host;

    /** @var \Aerys\Responder */
    private $responder;

    /** @var \Aerys\ErrorHandler */
    private $errorHandler;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var \Aerys\TimeReference */
    private $timeReference;

    /** @var \SplObjectStorage */
    private $observers;

    /** @var string[] */
    private $acceptWatcherIds = [];

    /** @var resource[] Server sockets. */
    private $boundServers = [];

    /** @var \Aerys\Client[] */
    private $clients = [];

    /** @var int */
    private $clientCount = 0;

    /** @var int[] */
    private $clientsPerIP = [];

    /** @var \Aerys\TimeoutCache */
    private $timeouts;

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

        $this->options = $options ?? new Options;
        $this->logger = $logger ?? new ConsoleLogger(new Console);

        $this->timeReference = new TimeReference;

        $this->timeouts = new TimeoutCache(
            $this->timeReference,
            $this->options->getConnectionTimeout()
        );

        $this->timeReference->onTimeUpdate($this->callableFromInstanceMethod("timeoutKeepAlives"));

        $this->observers = new \SplObjectStorage;
        $this->observers->attach($this->timeReference);

        if ($this->responder instanceof ServerObserver) {
            $this->observers->attach($this->responder);
        }

        $this->errorHandler = new DefaultErrorHandler;
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
        return $this->options;
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
    public function onTimeUpdate(callable $callback) {
        $this->timeReference->onTimeUpdate($callback);
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
        \assert($this->logger->debug("Starting") || true);

        $socketBinder = function ($address, $context) {
            if (!strncmp($address, "unix://", 7)) {
                @unlink(substr($address, 7));
            }

            if (!$socket = stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create($context))) {
                throw new \RuntimeException(sprintf("Failed binding socket on %s: [Err# %s] %s", $address, $errno, $errstr));
            }

            return $socket;
        };

        $this->boundServers = yield $bindSockets($this->generateAddressContextMap(), $socketBinder);

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

        $this->state = self::STARTED;
        assert($this->logger->debug("Started") || true);

        $onAcceptable = $this->callableFromInstanceMethod("onAcceptable");
        foreach ($this->boundServers as $serverName => $server) {
            $this->acceptWatcherIds[$serverName] = Loop::onReadable($server, $onAcceptable);
            $this->logger->info("Listening on {$serverName}");
        }
    }

    private function generateAddressContextMap(): array {
        $addrCtxMap = [];
        $addresses = $this->host->getAddresses();
        $tlsContext = $this->host->getTlsContext();
        $backlogSize = $this->options->getSocketBacklogSize();
        $shouldReusePort = !$this->options->isInDebugMode();

        foreach ($addresses as $address) {
            $context = ["socket" => [
                "backlog"      => $backlogSize,
                "so_reuseport" => $shouldReusePort,
                "so_reuseaddr" => \stripos(PHP_OS, "WIN") === 0, // SO_REUSEADDR has SO_REUSEPORT semantics on Windows
                "ipv6_v6only"  => true,
            ]];

            if ($tlsContext) {
                if (self::hasAlpnSupport()) {
                    $protocols = [];

                    if ($this->options->isHttp2Enabled()) {
                        $protocols[] = "h2";
                    }

                    if ($this->options->isHttp1Enabled()) {
                        $protocols[] = "http1.1";
                    }

                    $tlsContext["alpn_protocols"] = \implode(", ", $protocols);
                } elseif ($this->options->isHttp2Enabled()) {
                    $this->logger->alert("HTTP/2 requires ALPN support; HTTP/2 will not available over TLS");
                }

                $context["ssl"] = $tlsContext;
            }
            $addrCtxMap[$address] = $context;
        }

        return $addrCtxMap;
    }

    private function onAcceptable(string $watcherId, $server) {
        if (!$socket = @\stream_socket_accept($server, 0)) {
            return;
        }

        $client = new Client(
            $socket,
            $this->responder,
            $this->errorHandler,
            $this->logger,
            $this->options,
            $this->timeouts
        );

        \assert($this->logger->debug("Accept {$client->getRemoteAddress()}:{$client->getRemotePort()} on " .
                stream_socket_get_name($socket, false) . " #" . (int) $socket) || true);

        $net = $client->getNetworkId();

        if (!isset($this->clientsPerIP[$net])) {
            $this->clientsPerIP[$net] = 0;
        }

        $client->onClose(function (Client $client) {
            unset($this->clients[$client->getId()]);

            $net = $client->getNetworkId();
            if (--$this->clientsPerIP[$net] === 0) {
                unset($this->clientsPerIP[$net]);
            }

            --$this->clientCount;
        });

        if ($this->clientCount++ === $this->options->getMaxConnections()
            || $this->clientsPerIP[$net]++ === $this->options->getMaxConnectionsPerIp()
        ) {
            \assert($this->logger->debug("Client denied: too many existing connections") || true);
            $client->close();
            return;
        }

        $this->clients[$client->getId()] = $client;

        if ($this->options->isHttp1Enabled()) {
            $client->start(new Http1Driver($this->options, $this->timeReference));
            return;
        }

        $client->start(new Http2Driver($this->options, $this->timeReference));
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
                return Promise\timeout($stopPromise, $this->options->getShutdownTimeout());
            case self::STOPPED:
                return new Success;
            default:
                return new Failure(new \Error(
                    "Cannot stop server: currently ".self::STATES[$this->state]
                ));
        }
    }

    private function doStop(): \Generator {
        \assert($this->logger->debug("Stopping") || true);
        $this->state = self::STOPPING;

        foreach ($this->acceptWatcherIds as $watcherId) {
            Loop::cancel($watcherId);
        }
        $this->boundServers = [];
        $this->acceptWatcherIds = [];

        try {
            $promises = [];
            foreach ($this->observers as $observer) {
                $promises[] = $observer->onStop($this);
            }
            yield $promises;
        } catch (\Throwable $exception) {
            // Exception will be rethrown below once all clients are disconnected.
        }

        foreach ($this->clients as $client) {
            // @TODO Alter client to return a promise indicating when all pending responses have completed.
            $client->close();
        }

        \assert($this->logger->debug("Stopped") || true);
        $this->state = self::STOPPED;

        if (isset($exception)) {
            throw new \RuntimeException("onStop observer failure", 0, $exception);
        }
    }

    private function timeoutKeepAlives(int $now) {
        $timeouts = [];
        foreach ($this->timeouts as $id => $expiresAt) {
            if ($now > $expiresAt) {
                $timeouts[] = $this->clients[$id];
            } else {
                break;
            }
        }

        /** @var \Aerys\Client $client */
        foreach ($timeouts as $id => $client) {
            // Do not close in case some longer response is taking more time to complete.
            if ($client->waitingOnResponse()) {
                $this->timeouts->clear($id);
            } else {
                // Timeouts are only active while Client is doing nothing (not sending nor receiving) and no pending
                // writes, hence we can just fully close here
                $client->close();
            }
        }
    }

    public function __debugInfo() {
        return [
            "state" => $this->state,
            "host" => $this->host,
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
