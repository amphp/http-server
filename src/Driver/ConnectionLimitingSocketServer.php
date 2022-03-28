<?php

namespace Amp\Http\Server\Driver;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Socket\BindContext;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketServer;

final class ConnectionLimitingSocketServer implements SocketServer
{
    private int $connectionCount = 0;

    private ?DeferredFuture $deferredFuture = null;

    public function __construct(
        private readonly SocketServer $delegate,
        private readonly int $connectionLimit,
    ) {
    }

    public function accept(?Cancellation $cancellation = null): ?EncryptableSocket
    {
        $this->deferredFuture?->getFuture()->await();

        $socket = $this->delegate->accept();
        if (!$socket) {
            return null;
        }

        if (++$this->connectionCount >= $this->connectionLimit) {
            $this->deferredFuture = new DeferredFuture();
        }

        $socket->onClose(function (): void {
            --$this->connectionCount;
            $this->deferredFuture?->complete();
            $this->deferredFuture = null;
        });

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
