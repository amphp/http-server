<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\Cancellation;
use Amp\Socket\BindContext;
use Amp\Socket\ServerSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Sync\Semaphore;

final class ConnectionLimitingServerSocket implements ServerSocket
{
    public function __construct(
        private readonly ServerSocket $socketServer,
        private readonly Semaphore $semaphore,
    ) {
    }

    public function accept(?Cancellation $cancellation = null): ?Socket
    {
        $lock = $this->semaphore->acquire();

        $socket = $this->socketServer->accept($cancellation);
        if (!$socket) {
            $lock->release();
            return null;
        }

        $socket->onClose($lock->release(...));

        return $socket;
    }

    public function close(): void
    {
        $this->socketServer->close();
    }

    public function isClosed(): bool
    {
        return $this->socketServer->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->socketServer->onClose($onClose);
    }

    public function getAddress(): SocketAddress
    {
        return $this->socketServer->getAddress();
    }

    public function getBindContext(): BindContext
    {
        return $this->socketServer->getBindContext();
    }
}
