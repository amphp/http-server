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
use Amp\Http\Server\Internal\PerformanceRecommender;
use Amp\Http\Server\Middleware\CompressionMiddleware;
use Amp\Socket\BindContext;
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
    private HttpServerStatus $status = HttpServerStatus::Stopped;

    private readonly ServerSocketFactory $socketServerFactory;

    private readonly HttpDriverFactory $httpDriverFactory;

    private readonly ClientFactory $clientFactory;

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

    public function __construct(
        private readonly PsrLogger $logger,
        ?ServerSocketFactory $serverSocketFactory = null,
        ?ClientFactory $clientFactory = null,
        ?HttpDriverFactory $httpDriverFactory = null,
        private readonly bool $enableCompression = true,
    ) {
        if (!$serverSocketFactory) {
            $this->socketServerFactory = new ConnectionLimitingServerSocketFactory(new LocalSemaphore(1000));
            $this->logger->notice("Total client connections are limited to 1000");
        } else {
            $this->socketServerFactory = $serverSocketFactory;
        }

        $this->clientFactory = $clientFactory ?? new ConnectionLimitingClientFactory($this->logger);
        $this->httpDriverFactory = $httpDriverFactory ?? new DefaultHttpDriverFactory($this->logger);

        $this->onStart((new PerformanceRecommender($this->logger))->onStart(...));
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

    /**
     * Retrieve the current server status.
     */
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

    /**
     * Start the server.
     */
    public function start(RequestHandler $requestHandler, ErrorHandler $errorHandler): void
    {
        if (empty($this->addresses)) {
            throw new \Error(\sprintf('No bind addresses specified; Call %s::expose() to add some', self::class));
        }

        if ($this->status !== HttpServerStatus::Stopped) {
            throw new \Error("Cannot start server: " . $this->status->getLabel());
        }

        if ($this->enableCompression) {
            if (!\extension_loaded('zlib')) {
                $this->logger->warning(
                    "The zlib extension is not loaded which prevents using compression. " .
                    "Either activate the zlib extension or disable compression in the server's options."
                );
            } else {
                $requestHandler = Middleware\stack($requestHandler, new CompressionMiddleware);
            }
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

                $this->servers[] = $this->socketServerFactory->listen($address, $bindContext?->withTlsContext($tlsContext));
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
            $this->logger->error("Exception while handling client {address}", [
                'address' => $socket->getRemoteAddress(),
                'exception' => $exception,
            ]);

            $socket->close();
        }
    }

    /**
     * Stop the server.
     */
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
