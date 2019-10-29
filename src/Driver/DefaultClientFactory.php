<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\RequestHandler;
use Psr\Log\LoggerInterface as PsrLogger;

final class DefaultClientFactory implements ClientFactory
{
    public function createClient(
        $socket,
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        PsrLogger $logger,
        Options $options,
        TimeoutCache $timeoutCache
    ): Client {
        return new RemoteClient($socket, $requestHandler, $errorHandler, $logger, $options, $timeoutCache);
    }
}
