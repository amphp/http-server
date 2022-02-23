<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\RequestHandler;
use Amp\Socket\Socket;
use Psr\Log\LoggerInterface as PsrLogger;

final class LoggingClientFactory implements ClientFactory
{
    private ClientFactory $delegate;

    public function __construct(ClientFactory $delegate)
    {
        $this->delegate = $delegate;
    }

    public function createClient(
        Socket $socket,
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        PsrLogger $logger,
        Options $options,
    ): ?Client {
        $logger->debug("Accepted {$socket->getRemoteAddress()} on {$socket->getLocalAddress()}");

        return $this->delegate->createClient($socket, $requestHandler, $errorHandler, $logger, $options);
    }
}
