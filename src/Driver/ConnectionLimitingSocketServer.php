<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\Cancellation;
use Amp\Socket\BindContext;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketServer;
use Amp\Sync\Semaphore;

final class ConnectionLimitingSocketServer implements SocketServer
{
    public function __construct(
        private readonly SocketServer $socketServer,
        private readonly Semaphore $semaphore,
    ) {
    }

    public function accept(?Cancellation $cancellation = null): ?EncryptableSocket
    {
        $lock = $this->semaphore->acquire();

        $socket = $this->socketServer->accept();
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

    public function reference(): void
    {
        $this->socketServer->reference();
    }

    public function unreference(): void
    {
        $this->socketServer->unreference();
    }

    public function getResource()
    {
        return $this->socketServer->getResource();
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
