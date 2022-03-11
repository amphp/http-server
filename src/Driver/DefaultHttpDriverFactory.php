<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\RequestHandler;
use Psr\Log\LoggerInterface;

final class DefaultHttpDriverFactory implements HttpDriverFactory
{
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

    public function createHttpDriver(Client $client): HttpDriver
    {
        if ($client->getTlsInfo()?->getApplicationLayerProtocol() === "h2") {
            return new Http2Driver(
                $this->requestHandler,
                $this->errorHandler,
                $this->logger,
                $this->options
            );
        }

        return new Http1Driver(
            $this->requestHandler,
            $this->errorHandler,
            $this->logger,
            $this->options,
        );
    }
}
