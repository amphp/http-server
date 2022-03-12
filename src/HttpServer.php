<?php

namespace Amp\Http\Server;

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
    private HttpServerStatus $status = HttpServerStatus::Stopped;

    private Options $options;

    private ErrorHandler $errorHandler;

    private ClientFactory $clientFactory;

    private HttpDriverFactory $driverFactory;

    /** @var SocketServer[] */
    private array $sockets;

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
        $this->driverFactory = $driverFactory ?? new DefaultHttpDriverFactory($this->requestHandler, $this->errorHandler, $this->logger, $this->options);
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
            $this->driverFactory->setupSocketServer($socket);

            $scheme = $socket->getBindContext()?->getTlsContext() !== null ? 'https' : 'http';
            $serverName = $socket->getAddress()->toString();

            $this->logger->info("Listening on {$scheme}://{$serverName}/");

            async(function () use ($socket): void {
                while ($client = $socket->accept()) {
                    $this->accept($client);
                }
            });
        }
    }

    private function accept(Socket\EncryptableSocket $clientSocket): void
    {
        $client = $this->clientFactory->createClient($clientSocket);
        if ($client === null) {
            return;
        }

        $httpDriver = $this->driverFactory->createHttpDriver($client);

        try {
            $httpDriver->handleClient(
                $client,
                $clientSocket,
                $clientSocket,
            );
        } catch (ClientException) {
            $clientSocket->close();
        } catch (\Throwable $exception) {
            \assert(!$this->getLogger()->debug("Exception while handling client {address}", [
                'address' => $clientSocket->getRemoteAddress(),
                'exception' => $exception,
            ]));

            $clientSocket->close();
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

        foreach ($this->sockets as $socket) {
            $socket->close();
        }

        $this->logger->debug("Stopped server");
        $this->status = HttpServerStatus::Stopped;
    }
}
