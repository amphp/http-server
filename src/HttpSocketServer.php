<?php

namespace Amp\Http\Server;

use Amp\CompositeException;
use Amp\Future;
use Amp\Http\Server\Driver\ClientFactory;
use Amp\Http\Server\Driver\ConnectionLimitingClientFactory;
use Amp\Http\Server\Driver\ConnectionLimitingSocketServerFactory;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Driver\HttpDriverFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\Internal\PerformanceRecommender;
use Amp\Http\Server\Middleware\CompressionMiddleware;
use Amp\Socket\BindContext;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\ServerTlsContext;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketServer;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;

final class HttpSocketServer implements HttpServer
{
    private HttpServerStatus $status = HttpServerStatus::Stopped;

    private readonly Options $options;

    private readonly HttpDriverFactory $driverFactory;

    private readonly ClientFactory $clientFactory;

    private ?RequestHandler $requestHandler = null;
    private ?ErrorHandler $errorHandler = null;

    /** @var array<string, array{SocketAddress, BindContext|null}> */
    private array $addresses = [];

    /** @var list<SocketServer> */
    private array $servers = [];

    /** @var list<\Closure(ServerLifecycle):void> */
    private array $onStart = [];

    /** @var list<\Closure(ServerLifecycle):void> */
    private array $onStop = [];

    /**
     * @param Options|null $options Null creates an Options object with all default options.
     */
    public function __construct(
        private readonly PsrLogger $logger,
        ?Options $options = null,
        ?ClientFactory $clientFactory = null,
        ?HttpDriverFactory $driverFactory = null,
    ) {
        $this->options = $options ?? new Options;

        $this->clientFactory = $clientFactory ?? new ConnectionLimitingClientFactory(
                $this->logger,
                $this->options->getConnectionsPerIpLimit(),
                new SocketClientFactory($this->options->getTlsSetupTimeout()),
            );

        $this->driverFactory = $driverFactory ?? new DefaultHttpDriverFactory(
                $this->logger,
                $this->options,
                new ConnectionLimitingSocketServerFactory($this->options->getConnectionLimit()),
            );

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

    /**
     * Retrieve the server options object.
     */
    public function getOptions(): Options
    {
        return $this->options;
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
        if (!$this->requestHandler) {
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
                $scheme = $server->getBindContext()?->getTlsContext() !== null ? 'https' : 'http';
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
        $tlsContext = $server->getBindContext()?->getTlsContext();
        while ($socket = $server->accept()) {
            EventLoop::queue($this->handleClient(...), $socket, $tlsContext);
        }
    }

    private function handleClient(EncryptableSocket $socket, ?ServerTlsContext $tlsContext): void
    {
        try {
            $client = $this->clientFactory->createClient($socket, $tlsContext);
            if (!$client) {
                $socket->close();
                return;
            }

            $driver = $this->driverFactory->createHttpDriver($this->requestHandler, $this->errorHandler, $client);

            $driver->handleClient($client, $socket, $socket);
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

        $futures = [];
        foreach ($this->onStop as $onStop) {
            $futures[] = async($onStop, $this);
        }

        [$exceptions] = Future\awaitAll($futures);

        foreach ($this->servers as $server) {
            $server->close();
        }

        $this->logger->debug("Stopped server");
        $this->status = HttpServerStatus::Stopped;

        $this->requestHandler = null;
        $this->errorHandler = null;

        if (!empty($exceptions)) {
            throw new CompositeException($exceptions, "HTTP server onStop failure");
        }
    }
}
