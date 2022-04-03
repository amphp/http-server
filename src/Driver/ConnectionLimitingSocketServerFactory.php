<?php

namespace Amp\Http\Server\Driver;

use Amp\Socket\BindContext;
use Amp\Socket\ResourceSocketServerFactory;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketServer;
use Amp\Socket\SocketServerFactory;
use Amp\Sync\Semaphore;

final class ConnectionLimitingSocketServerFactory implements SocketServerFactory
{
    public function __construct(
        private readonly Semaphore $semaphore,
        private readonly SocketServerFactory $socketServerFactory = new ResourceSocketServerFactory,
    ) {
    }

    public function listen(SocketAddress $address, ?BindContext $bindContext = null): SocketServer
    {
        return new ConnectionLimitingSocketServer(
            $this->socketServerFactory->listen($address, $bindContext),
            $this->semaphore,
        );
    }
}
