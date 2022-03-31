<?php

namespace Amp\Http\Server\Driver;

use Amp\Socket\BindContext;
use Amp\Socket\ResourceSocketServerFactory;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketServer;
use Amp\Socket\SocketServerFactory;
use Psr\Log\LoggerInterface as PsrLogger;

class ConnectionLimitingSocketServerFactory implements SocketServerFactory
{
    public function __construct(
        PsrLogger $logger,
        private readonly int $connectionLimit = 1000,
        private readonly SocketServerFactory $delegate = new ResourceSocketServerFactory,
    ) {
        $logger->notice("Total client connections are limited to {$this->connectionLimit}");
    }

    public function listen(SocketAddress $address, ?BindContext $bindContext = null): SocketServer
    {
        return new ConnectionLimitingSocketServer(
            $this->delegate->listen($address, $bindContext),
            $this->connectionLimit,
        );
    }
}
