<?php

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
        private readonly SocketServer $delegate,
        private readonly Semaphore $semaphore,
    ) {
    }

    public function accept(?Cancellation $cancellation = null): ?EncryptableSocket
    {
        $lock = $this->semaphore->acquire();

        $socket = $this->delegate->accept();
        if (!$socket) {
            $lock->release();
            return null;
        }

        $socket->onClose($lock->release(...));

        return $socket;
    }

    public function close(): void
    {
        $this->delegate->close();
    }

    public function isClosed(): bool
    {
        return $this->delegate->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->delegate->onClose($onClose);
    }

    public function reference(): void
    {
        $this->delegate->reference();
    }

    public function unreference(): void
    {
        $this->delegate->unreference();
    }

    public function getResource()
    {
        return $this->delegate->getResource();
    }

    public function getAddress(): SocketAddress
    {
        return $this->delegate->getAddress();
    }

    public function getBindContext(): BindContext
    {
        return $this->delegate->getBindContext();
    }
}
