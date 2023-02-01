<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\Socket\BindContext;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\Socket\ServerSocket;
use Amp\Socket\ServerSocketFactory;
use Amp\Socket\SocketAddress;
use Amp\Sync\Semaphore;

final class ConnectionLimitingServerSocketFactory implements ServerSocketFactory
{
    public function __construct(
        private readonly Semaphore $semaphore,
        private readonly ServerSocketFactory $socketServerFactory = new ResourceServerSocketFactory(),
    ) {
    }

    public function listen(SocketAddress|string $address, ?BindContext $bindContext = null): ServerSocket
    {
        return new ConnectionLimitingServerSocket(
            $this->socketServerFactory->listen($address, $bindContext),
            $this->semaphore,
        );
    }
}
