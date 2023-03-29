<?php declare(strict_types=1);

namespace Amp\Http\Server;

use Amp\CompositeException;
use Amp\Future;
use Amp\Http\Server\Driver\ClientFactory;
use Amp\Http\Server\Driver\ConnectionLimitingClientFactory;
use Amp\Http\Server\Driver\ConnectionLimitingServerSocketFactory;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Driver\HttpDriver;
use Amp\Http\Server\Driver\HttpDriverFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\Middleware\AllowedMethodsMiddleware;
use Amp\Http\Server\Middleware\CompressionMiddleware;
use Amp\Socket\BindContext;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\Socket\ServerSocket;
use Amp\Socket\ServerSocketFactory;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Sync\LocalSemaphore;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;

final class SocketHttpServer implements HttpServer
{
    private const DEFAULT_CONNECTION_LIMIT = 1000;
    private const DEFAULT_CONNECTIONS_PER_IP_LIMIT = 10;

    private HttpServerStatus $status = HttpServerStatus::Stopped;

    private readonly HttpDriverFactory $httpDriverFactory;

    /** @var array<string, array{SocketAddress, BindContext|null}> */
    private array $addresses = [];

    /** @var list<ServerSocket> */
    private array $servers = [];

    /** @var array<int, HttpDriver> */
    private array $drivers = [];

    /** @var list<\Closure(HttpServer):void> */
    private array $onStart = [];

    /** @var list<\Closure(HttpServer):void> */
    private array $onStop = [];

    /**
     * Creates an instance appropriate for direct access by the public.
     *
     * @param CompressionMiddleware|null $compressionMiddleware Use null to disable compression.
     * @param positive-int $connectionLimit Default is {@see self::DEFAULT_CONNECTION_LIMIT}.
     * @param positive-int $connectionLimitPerIp Default  is {@see self::DEFAULT_CONNECTIONS_PER_IP_LIMIT}.
     * @param array|null $allowedMethods Use null to disable request method filtering.
     */
    public static function forEndpoint(
        PsrLogger $logger,
        ?CompressionMiddleware $compressionMiddleware = new CompressionMiddleware(),
        int $connectionLimit = self::DEFAULT_CONNECTION_LIMIT,
        int $connectionLimitPerIp = self::DEFAULT_CONNECTIONS_PER_IP_LIMIT,
        ?array $allowedMethods = AllowedMethodsMiddleware::DEFAULT_ALLOWED_METHODS,
        ?HttpDriverFactory $httpDriverFactory = null,
    ): self {
        $serverSocketFactory = new ConnectionLimitingServerSocketFactory(new LocalSemaphore($connectionLimit));

        $logger->notice(\sprintf("Total client connections are limited to %d.", $connectionLimit));

        $clientFactory = new ConnectionLimitingClientFactory(
            new SocketClientFactory($logger),
            $logger,
            $connectionLimitPerIp,
        );

        $logger->notice(\sprintf(
            "Client connections are limited to %s per IP address (excluding localhost).",
            $connectionLimitPerIp,
        ));

        return new self(
            $logger,
            $serverSocketFactory,
            $clientFactory,
            $compressionMiddleware,
            $allowedMethods,
            $httpDriverFactory,
        );
    }

    /**
     * Creates an instance appropriate for use when behind a proxy service such as nginx. It is not recommended
     * to allow public traffic to access the created server directly. Compression is disabled, there are no limits
     * on the total number of connections or connections per IP, and methods are not filtered by default.
     *
     * @param list<non-empty-string>|null $allowedMethods Use null to disable request method filtering.
     */
    public static function forBehindProxy(
        PsrLogger $logger,
        ?array $allowedMethods = null,
        ?HttpDriverFactory $httpDriverFactory = null,
    ): self {
        return new self(
            $logger,
            new ResourceServerSocketFactory(),
            new SocketClientFactory($logger),
            allowedMethods: $allowedMethods,
            httpDriverFactory: $httpDriverFactory,
        );
    }

    /**
     * @param list<non-empty-string>|null $allowedMethods Use null to disable request method filtering.
     */
    public function __construct(
        private readonly PsrLogger $logger,
        private readonly ServerSocketFactory $serverSocketFactory,
        private readonly ClientFactory $clientFactory,
        private readonly ?CompressionMiddleware $compressionMiddleware = null,
        private readonly ?array $allowedMethods = AllowedMethodsMiddleware::DEFAULT_ALLOWED_METHODS,
        ?HttpDriverFactory $httpDriverFactory = null,
    ) {
        $this->httpDriverFactory = $httpDriverFactory ?? new DefaultHttpDriverFactory($logger);
    }

    public function expose(SocketAddress|string $socketAddress, ?BindContext $bindContext = null): void
    {
        if (\is_string($socketAddress)) {
            $socketAddress = SocketAddress\fromString($socketAddress);
        }

        $name = $socketAddress->toString();
        if (isset($this->addresses[$name])) {
            throw new \Error(\sprintf('Already exposing %s on HTTP server', $name));
        }

        $this->addresses[$name] = [$socketAddress, $bindContext];
    }

    public function getServers(): array
    {
        if ($this->status !== HttpServerStatus::Started) {
            throw new \Error('Cannot get the list of socket servers when the HTTP server is not running');
        }

        return $this->servers;
    }

    public function getStatus(): HttpServerStatus
    {
        return $this->status;
    }

    public function onStart(\Closure $onStart): void
    {
        $this->onStart[] = $onStart;
    }

    public function onStop(\Closure $onStop): void
    {
        $this->onStop[] = $onStop;
    }

    public function start(RequestHandler $requestHandler, ErrorHandler $errorHandler): void
    {
        if (empty($this->addresses)) {
            throw new \Error(\sprintf('No bind addresses specified; Call %s::expose() to add some', self::class));
        }

        if ($this->status !== HttpServerStatus::Stopped) {
            throw new \Error("Cannot start server: " . $this->status->getLabel());
        }

        if (\ini_get("zend.assertions") === "1") {
            $this->logger->warning(
                "Running in production with assertions enabled is not recommended; it has a negative impact " .
                "on performance. Disable assertions in php.ini (zend.assertions = -1) for best performance."
            );
        }

        if (\extension_loaded("xdebug")) {
            $this->logger->warning("The 'xdebug' extension is loaded, which has a major impact on performance.");
        }

        if ($this->compressionMiddleware) {
            if (!\extension_loaded('zlib')) {
                $this->logger->warning(
                    "The zlib extension is not loaded which prevents using compression. " .
                    "Either activate the zlib extension or disable compression in the server's options."
                );
            } else {
                $this->logger->notice('Response compression enabled.');
                $requestHandler = Middleware\stack($requestHandler, $this->compressionMiddleware);
            }
        }

        if ($this->allowedMethods !== null) {
            $this->logger->notice(\sprintf(
                'Request methods restricted to %s.',
                \implode(', ', $this->allowedMethods),
            ));

            $requestHandler = Middleware\stack(
                $requestHandler,
                new AllowedMethodsMiddleware($errorHandler, $this->logger, $this->allowedMethods),
            );
        }

        $this->logger->debug("Starting server");
        $this->status = HttpServerStatus::Starting;

        try {
            $futures = [];
            foreach ($this->onStart as $onStart) {
                $futures[] = async($onStart, $this);
            }

            [$exceptions] = Future\awaitAll($futures);

            if (!empty($exceptions)) {
                throw new CompositeException($exceptions);
            }

            /**
             * @var SocketAddress $address
             * @var BindContext|null $bindContext
             */
            foreach ($this->addresses as [$address, $bindContext]) {
                $tlsContext = $bindContext?->getTlsContext()?->withApplicationLayerProtocols(
                    $this->httpDriverFactory->getApplicationLayerProtocols(),
                );

                $this->servers[] = $this->serverSocketFactory->listen(
                    $address,
                    $bindContext?->withTlsContext($tlsContext),
                );
            }

            $this->logger->info("Started server");
            $this->status = HttpServerStatus::Started;

            foreach ($this->servers as $server) {
                $scheme = $server->getBindContext()->getTlsContext() !== null ? 'https' : 'http';
                $serverName = $server->getAddress()->toString();

                $this->logger->info("Listening on {$scheme}://{$serverName}/");

                EventLoop::queue($this->accept(...), $server, $requestHandler, $errorHandler);
            }
        } catch (\Throwable $exception) {
            try {
                $this->status = HttpServerStatus::Started;
                $this->stop();
            } finally {
                throw $exception;
            }
        }
    }

    private function accept(
        ServerSocket $server,
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
    ): void {
        while ($socket = $server->accept()) {
            EventLoop::queue($this->handleClient(...), $socket, $requestHandler, $errorHandler);
        }
    }

    private function handleClient(
        Socket $socket,
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
    ): void {
        try {
            $client = $this->clientFactory->createClient($socket);
            if (!$client) {
                $socket->close();
                return;
            }

            $id = $client->getId();

            if ($this->status !== HttpServerStatus::Started) {
                $client->close();
                return;
            }

            $this->drivers[$id] = $driver = $this->httpDriverFactory->createHttpDriver(
                $requestHandler,
                $errorHandler,
                $client,
            );

            try {
                $driver->handleClient($client, $socket, $socket);
            } finally {
                unset($this->drivers[$id]);
            }
        } catch (\Throwable $exception) {
            $this->logger->error("Exception while handling client {$socket->getRemoteAddress()->toString()}", [
                'address' => $socket->getRemoteAddress(),
                'exception' => $exception,
            ]);

            $socket->close();
        }
    }

    public function stop(): void
    {
        if ($this->status === HttpServerStatus::Stopped) {
            return;
        }

        if ($this->status !== HttpServerStatus::Started) {
            throw new \Error("Cannot stop server: " . $this->status->getLabel());
        }

        $this->logger->info("Stopping server");
        $this->status = HttpServerStatus::Stopping;

        foreach ($this->servers as $server) {
            $server->close();
        }

        $futures = [];
        foreach ($this->onStop as $onStop) {
            $futures[] = async($onStop, $this);
        }

        [$onStopExceptions] = Future\awaitAll($futures);

        $futures = [];
        foreach ($this->drivers as $driver) {
            $futures[] = async($driver->stop(...));
        }

        [$driverExceptions] = Future\awaitAll($futures);

        $exceptions = \array_merge($onStopExceptions, $driverExceptions);

        $this->logger->debug("Stopped server");
        $this->status = HttpServerStatus::Stopped;

        $this->servers = [];

        if (!empty($exceptions)) {
            throw new CompositeException($exceptions);
        }
    }
}
