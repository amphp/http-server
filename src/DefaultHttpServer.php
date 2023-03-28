<?php declare(strict_types=1);

namespace Amp\Http\Server;

use Amp\Http\Server\Driver\ClientFactory;
use Amp\Http\Server\Driver\ConnectionLimitingClientFactory;
use Amp\Http\Server\Driver\ConnectionLimitingServerSocketFactory;
use Amp\Http\Server\Driver\HttpDriverFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\Middleware\AllowedMethodsMiddleware;
use Amp\Http\Server\Middleware\CompressionMiddleware;
use Amp\Socket\BindContext;
use Amp\Socket\ServerSocketFactory;
use Amp\Socket\SocketAddress;
use Amp\Sync\LocalSemaphore;
use Psr\Log\LoggerInterface as PsrLogger;

final class DefaultHttpServer implements HttpServer
{
    private const DEFAULT_CONNECTION_LIMIT = 1000;
    private const DEFAULT_CONNECTIONS_PER_IP_LIMIT = 10;

    private readonly SocketHttpServer $server;

    private CompressionMiddleware|bool $compressionMiddleware = true;

    private AllowedMethodsMiddleware|bool $allowedMethodsMiddleware = true;

    public function __construct(
        private readonly PsrLogger $logger,
        ?ServerSocketFactory $serverSocketFactory = null,
        ?ClientFactory $clientFactory = null,
        ?HttpDriverFactory $httpDriverFactory = null,
    ) {
        if (!$serverSocketFactory) {
            $serverSocketFactory = new ConnectionLimitingServerSocketFactory(
                new LocalSemaphore(self::DEFAULT_CONNECTION_LIMIT),
            );
            $this->logger->notice(\sprintf(
                "Total client connections are limited to %d.",
                self::DEFAULT_CONNECTION_LIMIT,
            ));
        }

        if (!$clientFactory) {
            $clientFactory = new ConnectionLimitingClientFactory(
                new SocketClientFactory($logger),
                $logger,
                self::DEFAULT_CONNECTIONS_PER_IP_LIMIT,
            );
            $this->logger->notice(\sprintf(
                "Client connections are limited to %s per IP address (excluding localhost).",
                self::DEFAULT_CONNECTIONS_PER_IP_LIMIT,
            ));
        }

        $this->server = new SocketHttpServer($logger, $serverSocketFactory, $clientFactory, $httpDriverFactory);
    }

    public function expose(SocketAddress|string $socketAddress, ?BindContext $bindContext = null): void
    {
        $this->server->expose($socketAddress, $bindContext);
    }

    public function setCompressionMiddleware(CompressionMiddleware $middleware): void
    {
        $this->compressionMiddleware = $middleware;
    }

    public function removeCompressionMiddleware(): void
    {
        $this->compressionMiddleware = false;
    }

    public function setAllowedMethodsMiddleware(AllowedMethodsMiddleware $middleware): void
    {
        $this->allowedMethodsMiddleware = $middleware;
    }

    public function removeAllowedMethodsMiddleware(): void
    {
        $this->allowedMethodsMiddleware = false;
    }

    public function getServers(): array
    {
        return $this->server->getServers();
    }

    public function getStatus(): HttpServerStatus
    {
        return $this->server->getStatus();
    }

    public function onStart(\Closure $onStart): void
    {
        $this->server->onStart($onStart);
    }

    public function onStop(\Closure $onStop): void
    {
        $this->server->onStop($onStop);
    }

    public function start(RequestHandler $requestHandler, ErrorHandler $errorHandler): void
    {
        if ($this->compressionMiddleware) {
            if (!\extension_loaded('zlib')) {
                $this->logger->warning(
                    "The zlib extension is not loaded which prevents using compression. " .
                    "Either activate the zlib extension or disable compression in the server's options."
                );
            } else {
                $this->logger->notice('Response compression enabled.');
                $middleware = \is_bool($this->compressionMiddleware)
                    ? new CompressionMiddleware()
                    : $this->compressionMiddleware;

                $requestHandler = Middleware\stack($requestHandler, $middleware);
            }
        }

        if ($this->allowedMethodsMiddleware) {
            $middleware = \is_bool($this->allowedMethodsMiddleware)
                ? new AllowedMethodsMiddleware($errorHandler, $this->logger)
                : $this->allowedMethodsMiddleware;

            $this->logger->notice(\sprintf(
                'Request methods restricted to %s.',
                \implode(', ', $middleware->getAllowedMethods()),
            ));

            $requestHandler = Middleware\stack($requestHandler, $middleware);
        }

        $this->server->start($requestHandler, $errorHandler);
    }

    public function stop(): void
    {
        $this->server->stop();
    }
}
