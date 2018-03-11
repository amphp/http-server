<?php

namespace Amp\Http\Server;

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

    /** @var \Amp\Http\Server\Internal\Host */
    private $host;

    /** @var \Amp\Http\Server\Responder */
    private $responder;

    /** @var ErrorHandler */
    private $errorHandler;

    /** @var \Amp\Http\Server\HttpDriverFactory */
    private $driverFactory;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var \Amp\Http\Server\TimeReference */
    private $timeReference;

    /** @var \SplObjectStorage */
    private $observers;

    /** @var string[] */
    private $acceptWatcherIds = [];

    /** @var resource[] Server sockets. */
    private $boundServers = [];

    /** @var \Amp\Http\Server\Client[] */
    private $clients = [];

    /** @var int */
    private $clientCount = 0;

    /** @var int[] */
    private $clientsPerIP = [];

    /** @var \Amp\Http\Server\TimeoutCache */
    private $timeouts;

    /**
     * @param \Amp\Http\Server\Responder              $responder
     * @param Options|null                  $options Null creates an Options object with all default options.
     * @param \Psr\Log\LoggerInterface|null $logger Null automatically uses an instance of \Amp\Http\Server\ConsoleLogger.
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

        $this->errorHandler = new DefaultErrorHandler;
        $this->driverFactory = new DefaultHttpDriverFactory;
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
     * Define a custom HTTP driver factory.
     *
     * @param \Amp\Http\Server\HttpDriverFactory $driverFactory
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
     * @return \Amp\Http\Server\TimeReference
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

        $this->observers->attach($this->timeReference);

        if ($this->driverFactory instanceof ServerObserver) {
            $this->observers->attach($this->driverFactory);
        }

        if ($this->responder instanceof ServerObserver) {
            $this->observers->attach($this->responder);
        }

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
                $protocols = $this->driverFactory->getApplicationLayerProtocols();

                if (self::hasAlpnSupport()) {
                    $tlsContext["alpn_protocols"] = \implode(", ", $protocols);
                } elseif ($protocols) {
                    $this->logger->alert("ALPN not supported with the installed version of OpenSSL");
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

        if ($this->clientCount++ === $this->options->getMaxConnections()) {
            \assert($this->logger->debug("Client denied: too many existing connections") || true);
            $client->close();
            return;
        }

        $ip = $client->getRemoteAddress();
        $clientCount = $this->clientsPerIP[$net]++;

        // Connections on localhost are excluded from the connections per IP setting.
        // Checks IPv4 loopback (127.x), IPv6 loopback (::1) and IPv4-to-IPv6 mapped loopback.
        // Also excludes all connections that are via unix sockets.
        if ($clientCount === $this->options->getMaxConnectionsPerIp()
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
            $client->close();
        }

        \assert($this->logger->debug("Stopped") || true);
        $this->state = self::STOPPED;

        if (isset($exception)) {
            throw new \RuntimeException("onStop observer failure", 0, $exception);
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
