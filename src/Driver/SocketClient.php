<?php

namespace Amp\Http\Server\Driver;

use Amp\Socket\EncryptableSocket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketAddressType;
use Amp\Socket\TlsInfo;

final class SocketClient implements Client
{
    private readonly int $id;

    public function __construct(
        private readonly EncryptableSocket $socket,
    ) {
        $this->id = createClientId();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->socket->getRemoteAddress();
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->socket->getLocalAddress();
    }

    public function isUnix(): bool
    {
        return $this->getRemoteAddress()->getType() === SocketAddressType::Unix;
    }

    public function isEncrypted(): bool
    {
        return $this->socket->getTlsInfo() !== null;
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return $this->socket->getTlsInfo();
    }

    public function close(): void
    {
        $this->socket->close();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->socket->onClose($onClose);
    }

    public function isClosed(): bool
    {
        return $this->socket->isClosed();
    }
}
