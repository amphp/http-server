<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;

final class SocketClient implements Client
{
    private readonly int $id;

    public function __construct(
        private readonly Socket $socket,
    ) {
        $this->id = createClientId();
    }

    #[\Override]
    public function getId(): int
    {
        return $this->id;
    }

    #[\Override]
    public function getRemoteAddress(): SocketAddress
    {
        return $this->socket->getRemoteAddress();
    }

    #[\Override]
    public function getLocalAddress(): SocketAddress
    {
        return $this->socket->getLocalAddress();
    }

    #[\Override]
    public function getTlsInfo(): ?TlsInfo
    {
        return $this->socket->getTlsInfo();
    }

    #[\Override]
    public function close(): void
    {
        $this->socket->close();
    }

    #[\Override]
    public function onClose(\Closure $onClose): void
    {
        $this->socket->onClose($onClose);
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->socket->isClosed();
    }
}
