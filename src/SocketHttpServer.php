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
use Amp\Http\Server\Middleware\ConcurrencyLimitingMiddleware;
use Amp\Http\Server\Middleware\ExceptionHandlerMiddleware;
use Amp\Http\Server\Middleware\ForwardedHeaderType;
use Amp\Http\Server\Middleware\ForwardedMiddleware;
use Amp\Socket\BindContext;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\Socket\ServerSocket;
use Amp\Socket\ServerSocketFactory;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketException;
use Amp\Sync\LocalSemaphore;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;

final class SocketHttpServer implements HttpServer
{
    private const DEFAULT_CONCURRENCY_LIMIT = 1000;
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
     * @param positive-int|null $concurrencyLimit Default is {@see self::DEFAULT_CONCURRENCY_LIMIT}.
     *      Use null for no limit.
     * @param positive-int $connectionLimit Default is {@see self::DEFAULT_CONNECTION_LIMIT}.
     * @param positive-int $connectionLimitPerIp Default is {@see self::DEFAULT_CONNECTIONS_PER_IP_LIMIT}.
     * @param array<non-empty-string>|null $allowedMethods Use null to disable request method filtering.
     */
    public static function createForDirectAccess(
        PsrLogger $logger,
        bool $enableCompression = true,
        int $connectionLimit = self::DEFAULT_CONNECTION_LIMIT,
        int $connectionLimitPerIp = self::DEFAULT_CONNECTIONS_PER_IP_LIMIT,
        ?int $concurrencyLimit = self::DEFAULT_CONCURRENCY_LIMIT,
        ?array $allowedMethods = AllowedMethodsMiddleware::DEFAULT_ALLOWED_METHODS,
        ?HttpDriverFactory $httpDriverFactory = null,
        ?ExceptionHandler $exceptionHandler = null,
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

        $middleware = [];

        if ($exceptionHandler) {
            $middleware[] = new ExceptionHandlerMiddleware($exceptionHandler);
        }

        if ($concurrencyLimit !== null) {
            $logger->notice(\sprintf("Request concurrency limited to %s simultaneous requests", $concurrencyLimit));
            $middleware[] = new ConcurrencyLimitingMiddleware($concurrencyLimit);
        }

        if ($enableCompression && $compressionMiddleware = self::createCompressionMiddleware($logger)) {
            $middleware[] = $compressionMiddleware;
        }

        return new self(
            $logger,
            $serverSocketFactory,
            $clientFactory,
            $middleware,
            $allowedMethods,
            $httpDriverFactory,
        );
    }

    /**
     * Creates an instance appropriate for use when behind a proxy service such as nginx. It is not recommended
     * to allow public traffic to access the created server directly. There are no limits on the total number of
     * connections or connections per IP.
     *
     * @param positive-int|null $concurrencyLimit Default is {@see self::DEFAULT_CONCURRENCY_LIMIT}.
     *      Use null for no limit.
     * @param array<non-empty-string> $trustedProxies Array of IPv4 or IPv6 addresses with an optional subnet mask.
     *      e.g., '172.18.0.0/24'
     * @param array<non-empty-string>|null $allowedMethods Use null to disable request method filtering.
     */
    public static function createForBehindProxy(
        PsrLogger $logger,
        ForwardedHeaderType $headerType,
        array $trustedProxies,
        bool $enableCompression = true,
        ?int $concurrencyLimit = self::DEFAULT_CONCURRENCY_LIMIT,
        ?array $allowedMethods = AllowedMethodsMiddleware::DEFAULT_ALLOWED_METHODS,
        ?HttpDriverFactory $httpDriverFactory = null,
        ?ExceptionHandler $exceptionHandler = null,
    ): self {
        $middleware = [];

        if ($exceptionHandler) {
            $middleware[] = new ExceptionHandlerMiddleware($exceptionHandler);
        }

        if ($concurrencyLimit !== null) {
            $middleware[] = new ConcurrencyLimitingMiddleware($concurrencyLimit);
        }

        $middleware[] = new ForwardedMiddleware($headerType, $trustedProxies);

        if ($enableCompression && $compressionMiddleware = self::createCompressionMiddleware($logger)) {
            $middleware[] = $compressionMiddleware;
        }

        return new self(
            $logger,
            new ResourceServerSocketFactory(),
            new SocketClientFactory($logger),
            $middleware,
            $allowedMethods,
            $httpDriverFactory,
        );
    }

    private static function createCompressionMiddleware(PsrLogger $logger): ?CompressionMiddleware
    {
        if (!\extension_loaded('zlib')) {
            $logger->warning(
                'The zlib extension is not loaded which prevents using compression. ' .
                'Either activate the zlib extension or set $enableCompression to false'
            );

            return null;
        }

        return new CompressionMiddleware();
    }

    /**
     * @param array<Middleware> $middleware Default middlewares. You may also use {@see Middleware\stackMiddleware()}
     *      before passing the {@see RequestHandler} to {@see self::start()}.
     * @param array<non-empty-string>|null $allowedMethods Use null to disable request method filtering.
     */
    public function __construct(
        private readonly PsrLogger $logger,
        private readonly ServerSocketFactory $serverSocketFactory,
        private readonly ClientFactory $clientFactory,
        private readonly array $middleware = [],
        private readonly ?array $allowedMethods = AllowedMethodsMiddleware::DEFAULT_ALLOWED_METHODS,
        ?HttpDriverFactory $httpDriverFactory = null,
    ) {
        $this->httpDriverFactory = $httpDriverFactory ?? new DefaultHttpDriverFactory($logger);
    }

    /**
     * Listen for client connections on the given address. The socket server will be created using the
     * {@see ServerSocketFactory} provided to the constructor and the given {@see BindContext}.
     *
     * @throws SocketException
     */
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

    /**
     * May only be called when the server is running.
     *
     * @return list<ServerSocket>
     */
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

        $requestHandler = Middleware\stackMiddleware($requestHandler, ...$this->middleware);

        if ($this->allowedMethods !== null) {
            $this->logger->notice(\sprintf(
                'Request methods restricted to %s.',
                \implode(', ', $this->allowedMethods),
            ));

            $requestHandler = Middleware\stackMiddleware(
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

                /** @psalm-suppress PropertyTypeCoercion */
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

                // Using short-closure to avoid Psalm bug when using a first-class callable here.
                EventLoop::queue(fn () => $this->accept($server, $requestHandler, $errorHandler));
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
