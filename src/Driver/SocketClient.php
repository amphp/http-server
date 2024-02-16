<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\Quic\QuicConnection;
use Amp\Quic\QuicSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;

class SocketClient implements Client
{
    private readonly int $id;

    public function __construct(
        private readonly Client|Socket|QuicConnection $socket,
        int $id = null
    ) {
        $this->id = $id ?? createClientId();
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

    public function getTlsInfo(): ?TlsInfo
    {
        return $this->socket->getTlsInfo();
    }

    public function close(int $reason = 0): void
    {
        $this->socket->close($reason);
    }

    public function onClose(\Closure $onClose): void
    {
        $this->socket->onClose($onClose);
    }

    public function isClosed(): bool
    {
        return $this->socket->isClosed();
    }

    public function isQuicClient(): bool
    {
        return $this->socket instanceof QuicConnection || $this->socket instanceof QuicSocket;
    }
}
