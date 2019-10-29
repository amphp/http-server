<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\RequestHandler;
use Psr\Log\LoggerInterface as PsrLogger;

interface ClientFactory
{
    /**
     * @param resource       $socket Stream socket resource.
     * @param RequestHandler $requestHandler
     * @param ErrorHandler   $errorHandler
     * @param PsrLogger      $logger
     * @param Options        $options
     * @param TimeoutCache   $timeoutCache
     *
     * @return Client
     */
    public function createClient(
        $socket,
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        PsrLogger $logger,
        Options $options,
        TimeoutCache $timeoutCache
    ): Client;
}
