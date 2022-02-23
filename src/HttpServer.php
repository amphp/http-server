<?php

namespace Amp\Http\Server;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Driver\ClientFactory;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Driver\HttpDriverFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\Internal\PerformanceRecommender;
use Amp\Http\Server\Middleware\CompressionMiddleware;
use Amp\Socket;
use Amp\Socket\SocketServer;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\async;

final class HttpServer
{
    public const DEFAULT_SHUTDOWN_TIMEOUT = 3;

    private HttpServerStatus $status = HttpServerStatus::Stopped;

    private Options $options;

    private ErrorHandler $errorHandler;

    private ClientFactory $clientFactory;

    private HttpDriverFactory $driverFactory;

    /** @var SocketServer[] */
    private array $sockets = [];

    /** @var Client[] */
    private array $clients = [];

    private int $clientCount = 0;

    /** @var int[] */
    private array $clientsPerIP = [];

    /**
     * @param SocketServer[] $sockets
     * @param RequestHandler $requestHandler
     * @param PsrLogger $logger
     * @param Options|null $options Null creates an Options object with all default options.
     */
    public function __construct(
        array $sockets,
        private RequestHandler $requestHandler,
        private PsrLogger $logger,
        ?Options $options = null,
        ?ErrorHandler $errorHandler = null,
        ?HttpDriverFactory $driverFactory = null,
        ?ClientFactory $clientFactory = null,
    ) {
        if (!$sockets) {
            throw new \ValueError('Argument #1 ($sockets) can\'t be an empty array');
        }

        foreach ($sockets as $socket) {
            if (!$socket instanceof SocketServer) {
                throw new \TypeError(\sprintf('Argument #1 ($sockets) must be of type array<%s>', SocketServer::class));
            }
        }

        $this->options = $options ?? new Options;

        if ($this->options->isCompressionEnabled()) {
            if (!\extension_loaded('zlib')) {
                $this->logger->warning(
                    "The zlib extension is not loaded which prevents using compression. " .
                    "Either activate the zlib extension or disable compression in the server's options."
                );
            } else {
                $this->requestHandler = Middleware\stack($this->requestHandler, new CompressionMiddleware);
            }
        }

        $this->sockets = $sockets;
        $this->clientFactory = $clientFactory ?? new SocketClientFactory;
        $this->errorHandler = $errorHandler ?? new DefaultErrorHandler;
        $this->driverFactory = $driverFactory ?? new DefaultHttpDriverFactory;
    }

    /**
     * Define a custom HTTP driver factory.
     *
     * @throws \Error If the server has started.
     */
    public function setDriverFactory(HttpDriverFactory $driverFactory): void
    {
        if ($this->status !== HttpServerStatus::Stopped) {
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
        if ($this->status !== HttpServerStatus::Stopped) {
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
        if ($this->status !== HttpServerStatus::Stopped) {
            throw new \Error("Cannot set the error handler after the server has started");
        }

        $this->errorHandler = $errorHandler;
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
     * Start the server.
     */
    public function start(): void
    {
        if ($this->status !== HttpServerStatus::Stopped) {
            throw new \Error("Cannot start server: " . $this->status->getLabel());
        }

        $this->status = HttpServerStatus::Started;

        (new PerformanceRecommender())->onStart($this);
        $this->logger->info("Started server");

        foreach ($this->sockets as $socket) {
            $scheme = $socket->getBindContext()?->getTlsContext() !== null ? 'https' : 'http';
            $serverName = $socket->getAddress()->toString();

            $this->logger->info("Listening on {$scheme}://{$serverName}/");

            async(function () use ($socket) {
                while ($client = $socket->accept()) {
                    $this->accept($client);
                }
            });
        }
    }

    private function accept(Socket\EncryptableSocket $clientSocket): void
    {
        $client = $this->clientFactory->createClient(
            $clientSocket,
            $this->requestHandler,
            $this->errorHandler,
            $this->logger,
            $this->options,
        );

        $this->logger->debug("Accepted {$client->getRemoteAddress()} on {$client->getLocalAddress()} #{$client->getId()}");

        $ip = $net = $client->getRemoteAddress()->getHost();
        if (@\inet_pton($net) !== false && isset($net[4])) {
            $net = \substr($net, 0, 7 /* /56 block for IPv6 */);
        }

        if (!isset($this->clientsPerIP[$net])) {
            $this->clientsPerIP[$net] = 0;
        }

        $client->onClose(function (Client $client) use ($net): void {
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
     */
    public function stop(int $timeout = self::DEFAULT_SHUTDOWN_TIMEOUT): void
    {
        switch ($this->status) {
            case HttpServerStatus::Started:
                $this->shutdown();
                return;
            case HttpServerStatus::Stopped:
                return;
            default:
                throw new \Error("Cannot stop server: " . $this->status->getLabel());
        }
    }

    private function shutdown(): void
    {
        $this->logger->info("Stopping server");
        $this->status = HttpServerStatus::Stopping;

        foreach ($this->sockets as $socket) {
            $socket->close();
        }

        $this->logger->debug("Stopped server");
        $this->status = HttpServerStatus::Stopped;
    }

    public function __debugInfo(): array
    {
        return [
            "status" => $this->status,
            "sockets" => $this->sockets,
            "clients" => $this->clients,
        ];
    }
}
