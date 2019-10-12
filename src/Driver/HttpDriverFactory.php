<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Psr\Log\LoggerInterface as PsrLogger;

interface HttpDriverFactory
{
    /**
     * Selects an HTTP driver based on the given client.
     *
     * @param Client $client
     * @param Options $options
     * @param PsrLogger $logger
     * @param TimeReference $timeReference
     * @param ErrorHandler $errorHandler
     *
     * @return HttpDriver
     */
    public function selectDriver(
        Client $client,
        Options $options,
        PsrLogger $logger,
        TimeReference $timeReference,
        ErrorHandler $errorHandler
    ): HttpDriver;

    /**
     * @return string[] A list of supported application-layer protocols (ALPNs).
     */
    public function getApplicationLayerProtocols(): array;
}
