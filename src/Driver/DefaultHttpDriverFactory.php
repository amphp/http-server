<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\RequestHandler;
use Amp\Socket\BindContext;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketServer;
use Amp\Socket\SocketServerFactory;
use Psr\Log\LoggerInterface as PsrLogger;

final class DefaultHttpDriverFactory implements HttpDriverFactory
{
    public const ALPN = ["h2", "http/1.1"];

    private readonly SocketServerFactory $socketServerFactory;

    public function __construct(
        private readonly PsrLogger $logger,
        ?SocketServerFactory $socketServerFactory = null,
        private readonly int $streamTimeout = HttpDriver::DEFAULT_STREAM_TIMEOUT,
        private readonly int $connectionTimeout = HttpDriver::DEFAULT_CONNECTION_TIMEOUT,
        private readonly int $headerSizeLimit = HttpDriver::DEFAULT_HEADER_SIZE_LIMIT,
        private readonly int $bodySizeLimit = HttpDriver::DEFAULT_BODY_SIZE_LIMIT,
        private readonly int $streamThreshold = HttpDriver::DEFAULT_STREAM_THRESHOLD,
        private readonly array $allowedMethods = HttpDriver::DEFAULT_ALLOWED_METHODS,
        private readonly bool $allowHttp2Upgrade = false,
        private readonly bool $pushEnabled = true,
        private readonly TimeoutQueue $timeoutQueue = new DefaultTimeoutQueue,
    ) {
        $this->socketServerFactory = $socketServerFactory ?? new ConnectionLimitingSocketServerFactory($this->logger);
    }

    public function listen(SocketAddress $address, ?BindContext $bindContext = null): SocketServer
    {
        $tlsContext = $bindContext?->getTlsContext()?->withApplicationLayerProtocols(self::ALPN);

        return $this->socketServerFactory->listen($address, $bindContext?->withTlsContext($tlsContext));
    }

    public function createHttpDriver(
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        Client $client,
    ): HttpDriver {
        if ($client->getTlsInfo()?->getApplicationLayerProtocol() === "h2") {
            return new Http2Driver(
                timeoutQueue: $this->timeoutQueue,
                requestHandler: $requestHandler,
                errorHandler: $errorHandler,
                logger: $this->logger,
                streamTimeout: $this->streamTimeout,
                connectionTimeout: $this->connectionTimeout,
                headerSizeLimit: $this->headerSizeLimit,
                bodySizeLimit: $this->bodySizeLimit,
                streamThreshold: $this->streamThreshold,
                allowedMethods: $this->allowedMethods,
                pushEnabled: $this->pushEnabled,
            );
        }

        return new Http1Driver(
            timeoutQueue: $this->timeoutQueue,
            requestHandler: $requestHandler,
            errorHandler: $errorHandler,
            logger: $this->logger,
            connectionTimeout: $this->streamTimeout, // Intentional use of stream instead of connection timeout
            headerSizeLimit: $this->headerSizeLimit,
            bodySizeLimit: $this->bodySizeLimit,
            streamThreshold: $this->streamThreshold,
            allowedMethods: $this->allowedMethods,
            allowHttp2Upgrade: $this->allowHttp2Upgrade,
        );
    }
}
