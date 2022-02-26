<?php

namespace Amp\Http\Server\Driver;

use Amp\Socket\Socket;
use Psr\Log\LoggerInterface;

final class LoggingClientFactory implements ClientFactory
{
    private ClientFactory $delegate;
    private LoggerInterface $logger;

    public function __construct(ClientFactory $delegate, LoggerInterface $logger)
    {
        $this->delegate = $delegate;
        $this->logger = $logger;
    }

    public function createClient(Socket $socket): ?Client
    {
        $this->logger->debug("Accepted {$socket->getRemoteAddress()} on {$socket->getLocalAddress()}");

        return $this->delegate->createClient($socket);
    }
}
