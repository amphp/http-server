<?php

namespace Amp\Http\Server\Driver;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\RequestHandler;
use Psr\Log\LoggerInterface;

final class AlpnHttpDriver implements HttpDriver
{
    private ?HttpDriver $httpDriver = null;

    public function __construct(
        private RequestHandler $requestHandler,
        private ErrorHandler $errorHandler,
        private LoggerInterface $logger,
        private Options $options
    ) {
    }

    public function getApplicationLayerProtocols(): array
    {
        return ["h2", "http/1.1"];
    }

    public function handleClient(Client $client, ReadableStream $readableStream, WritableStream $writableStream): void
    {
        \assert(!isset($this->httpDriver));

        if ($client->getTlsInfo()?->getApplicationLayerProtocol() === "h2") {
            $this->httpDriver = new Http2Driver(
                $this->requestHandler,
                $this->errorHandler,
                $this->logger,
                $this->options
            );
        } else {
            $this->httpDriver = new Http1Driver(
                $this->requestHandler,
                $this->errorHandler,
                $this->logger,
                $this->options,
            );
        }

        $this->httpDriver->handleClient($client, $readableStream, $writableStream);
    }

    public function getPendingRequestCount(): int
    {
        return $this->httpDriver?->getPendingRequestCount() ?? 0;
    }

    public function stop(): void
    {
        $this->httpDriver?->stop();
    }
}
