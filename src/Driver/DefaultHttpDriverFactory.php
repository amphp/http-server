<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Psr\Log\LoggerInterface as PsrLogger;

final class DefaultHttpDriverFactory implements HttpDriverFactory
{
    /** {@inheritdoc} */
    public function selectDriver(
        Client $client,
        Options $options,
        PsrLogger $logger,
        TimeReference $timeReference,
        ErrorHandler $errorHandler
    ): HttpDriver {
        if ($client->isEncrypted() && $client->getTlsInfo()->getApplicationLayerProtocol() === "h2") {
            return new Http2Driver($options, $timeReference, $logger);
        }

        return new Http1Driver($options, $timeReference, $errorHandler, $logger);
    }

    /** {@inheritdoc} */
    public function getApplicationLayerProtocols(): array
    {
        return ["h2", "http/1.1"];
    }
}
