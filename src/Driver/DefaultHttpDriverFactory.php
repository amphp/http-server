<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Socket\SocketServer;
use Psr\Log\LoggerInterface;

final class DefaultHttpDriverFactory implements HttpDriverFactory
{
    public const ALPN = ["h2", "http/1.1"];

    public function __construct(
        private readonly ErrorHandler $errorHandler,
        private readonly LoggerInterface $logger,
        private readonly Options $options,
        private readonly TimeoutQueue $timeoutQueue = new DefaultTimeoutQueue,
    ) {
    }

    public function setUpSocketServer(SocketServer $server): SocketServer
    {
        $resource = $server->getResource();

        if ($resource && $server->getBindContext()?->getTlsContext()) {
            \stream_context_set_option($resource, 'ssl', 'alpn_protocols', \implode(', ', self::ALPN));
        }

        return $server;
    }

    public function createHttpDriver(Client $client): HttpDriver
    {
        if ($client->getTlsInfo()?->getApplicationLayerProtocol() === "h2") {
            return new Http2Driver(
                $this->timeoutQueue,
                $this->errorHandler,
                $this->logger,
                $this->options
            );
        }

        return new Http1Driver(
            $this->timeoutQueue,
            $this->errorHandler,
            $this->logger,
            $this->options,
        );
    }
}
