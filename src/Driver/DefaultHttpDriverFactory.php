<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\RequestHandler;
use Amp\Socket\BindContext;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketServer;
use Amp\Socket\SocketServerFactory;
use Psr\Log\LoggerInterface as PsrLogger;

final class DefaultHttpDriverFactory implements HttpDriverFactory
{
    public const ALPN = ["h2", "http/1.1"];

    public function __construct(
        private readonly PsrLogger $logger,
        private readonly Options $options,
        private readonly SocketServerFactory $socketServerFactory = new ConnectionLimitingSocketServerFactory,
        private readonly TimeoutQueue $timeoutQueue = new DefaultTimeoutQueue,
    ) {
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
                $this->timeoutQueue,
                $requestHandler,
                $errorHandler,
                $this->logger,
                $this->options
            );
        }

        return new Http1Driver(
            $this->timeoutQueue,
            $requestHandler,
            $errorHandler,
            $this->logger,
            $this->options,
        );
    }
}
