<?php

namespace Amp\Http\Server;

use Amp\CompositeException;
use Amp\Future;
use Amp\Http\Server\Driver\ClientFactory;
use Amp\Http\Server\Driver\ConnectionLimitingClientFactory;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Driver\HttpDriver;
use Amp\Http\Server\Driver\HttpDriverFactory;
use Amp\Http\Server\Internal\PerformanceRecommender;
use Amp\Http\Server\Middleware\CompressionMiddleware;
use Amp\Socket\BindContext;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketServer;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;

final class HttpSocketServer implements HttpServer
{
    private HttpServerStatus $status = HttpServerStatus::Stopped;

    private readonly HttpDriverFactory $driverFactory;

    private readonly ClientFactory $clientFactory;

    private ?RequestHandler $requestHandler = null;
    private ?ErrorHandler $errorHandler = null;

    /** @var array<string, array{SocketAddress, BindContext|null}> */
    private array $addresses = [];

    /** @var list<SocketServer> */
    private array $servers = [];

    /** @var array<int, HttpDriver> */
    private array $drivers = [];

    /** @var list<\Closure(HttpServer):void> */
    private array $onStart = [];

    /** @var list<\Closure(HttpServer):void> */
    private array $onStop = [];

    public function __construct(
        private readonly PsrLogger $logger,
        ?ClientFactory $clientFactory = null,
        ?HttpDriverFactory $driverFactory = null,
        private readonly bool $enableCompression = false,
    ) {
        $this->clientFactory = $clientFactory ?? new ConnectionLimitingClientFactory($this->logger);
        $this->driverFactory = $driverFactory ?? new DefaultHttpDriverFactory($this->logger);

        $this->onStart((new PerformanceRecommender())->onStart(...));
    }

    public function expose(SocketAddress $socketAddress, ?BindContext $bindContext = null): void
    {
        $name = $socketAddress->toString();
        if (isset($this->addresses[$name])) {
            throw new \Error(\sprintf('Already exposing %s on HTTP server', $name));
        }

        $this->addresses[$name] = [$socketAddress, $bindContext];
    }

    /**
     * Retrieve the current server status.
     */
    public function getStatus(): HttpServerStatus
    {
        return $this->status;
    }

    public function getRequestHandler(): RequestHandler
    {
        if (!$this->requestHandler) {
            throw new \Error('Cannot get the request handler when the server is not running');
        }

        return $this->requestHandler;
    }

    /**
     * Retrieve the error handler.
     */
    public function getErrorHandler(): ErrorHandler
    {
        if (!$this->errorHandler) {
            throw new \Error('Cannot get the error handler when the server is not running');
        }

        return $this->errorHandler;
    }

    /**
     * Retrieve the logger.
     */
    public function getLogger(): PsrLogger
    {
        return $this->logger;
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
    public function start(RequestHandler $requestHandler, ?ErrorHandler $errorHandler = null): void
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

        $this->requestHandler = $requestHandler;
        $this->errorHandler = $errorHandler ?? new DefaultErrorHandler();

        $this->logger->debug("Starting server");
        $this->status = HttpServerStatus::Starting;

        try {
            $futures = [];
            foreach ($this->onStart as $onStart) {
                $futures[] = async($onStart, $this);
            }

            [$exceptions] = Future\awaitAll($futures);

            if (!empty($exceptions)) {
                throw new CompositeException($exceptions, "HTTP server onStart failure");
            }

            foreach ($this->addresses as [$address, $bindContext]) {
                $this->servers[] = $this->driverFactory->listen($address, $bindContext);
            }

            $this->logger->info("Started server");
            $this->status = HttpServerStatus::Started;

            foreach ($this->servers as $server) {
                $scheme = $server->getBindContext()->getTlsContext() !== null ? 'https' : 'http';
                $serverName = $server->getAddress()->toString();

                $this->logger->info("Listening on {$scheme}://{$serverName}/");

                EventLoop::queue($this->accept(...), $server);
            }
        } catch (\Throwable $exception) {
            try {
                $this->stop();
            } finally {
                $this->requestHandler = null;
                $this->errorHandler = null;
                throw $exception;
            }
        }
    }

    private function accept(SocketServer $server): void
    {
        while ($socket = $server->accept()) {
            EventLoop::queue($this->handleClient(...), $socket);
        }
    }

    private function handleClient(EncryptableSocket $socket): void
    {
        try {
            $client = $this->clientFactory->createClient($socket);
            if (!$client) {
                $socket->close();
                return;
            }

            $id = $client->getId();

            if (!$this->requestHandler || !$this->errorHandler) {
                $client->close();
                return;
            }

            $this->drivers[$id] = $driver = $this->driverFactory->createHttpDriver(
                $this->requestHandler,
                $this->errorHandler,
                $client,
            );

            try {
                $driver->handleClient($client, $socket, $socket);
            } finally {
                unset($this->drivers[$id]);
            }
        } catch (\Throwable $exception) {
            $this->logger->debug("Exception while handling client {address}", [
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

        $this->requestHandler = null;
        $this->errorHandler = null;

        if (!empty($exceptions)) {
            throw new CompositeException($exceptions, "HTTP server onStop failure");
        }
    }
}
